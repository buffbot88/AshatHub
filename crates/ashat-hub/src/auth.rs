use std::{net::SocketAddr, time::Duration};

use axum::{
    extract::{ConnectInfo, FromRequestParts, State},
    http::{header, request::Parts, HeaderMap, HeaderValue, Method, StatusCode},
    response::{IntoResponse, Response},
    routing::{get, post},
    Json, Router,
};
use bcrypt::{hash, verify, DEFAULT_COST};
use rand::{distributions::Alphanumeric, Rng};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use sqlx::{FromRow, MySqlPool};
use uuid::Uuid;

use crate::{
    response::{error_response, rate_limit_response},
    AppState,
};

#[derive(Debug, Deserialize)]
pub(crate) struct LoginRequest {
    pub identifier: String,
    pub password: String,
}

#[derive(Debug, Deserialize)]
struct ProfileUpdate {
    display_name: Option<String>,
    email: Option<String>,
}

#[derive(Debug, Deserialize)]
struct RegisterRequest {
    username: String,
    email: String,
    password: String,
    display_name: Option<String>,
}

#[derive(Debug, Serialize)]
struct AuthResponse {
    authenticated: bool,
    user: Option<PublicUser>,
    csrf: Option<String>,
}

#[derive(Debug, Serialize)]
struct LoginResponse {
    authenticated: bool,
    user: PublicUser,
    csrf: String,
}

#[derive(Debug, Serialize)]
struct OkResponse {
    ok: bool,
}

#[derive(Debug, Clone, Serialize, FromRow)]
pub(crate) struct PublicUser {
    pub id: String,
    pub username: String,
    pub email: String,
    pub display_name: String,
    pub role: String,
}

#[derive(Debug, Clone)]
pub(crate) struct AuthenticatedUser(pub(crate) PublicUser);

#[axum::async_trait]
impl FromRequestParts<AppState> for AuthenticatedUser {
    type Rejection = Response;

    async fn from_request_parts(
        parts: &mut Parts,
        state: &AppState,
    ) -> Result<Self, Self::Rejection> {
        let Some(pool) = state.db.as_ref() else {
            return Err(error_response(
                StatusCode::SERVICE_UNAVAILABLE,
                "auth_unavailable",
            ));
        };
        match authenticated_user(pool, state, &parts.headers).await {
            Ok(Some(user)) => Ok(Self(user)),
            Ok(None) => {
                state.metrics.record_auth_failure();
                Err(error_response(StatusCode::UNAUTHORIZED, "unauthenticated"))
            }
            Err(error) => {
                tracing::error!(?error, "authenticated user lookup failed");
                Err(error_response(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "auth_unavailable",
                ))
            }
        }
    }
}

#[derive(Debug, Clone)]
pub(crate) struct AdminUser(pub(crate) PublicUser);

#[axum::async_trait]
impl FromRequestParts<AppState> for AdminUser {
    type Rejection = Response;

    async fn from_request_parts(
        parts: &mut Parts,
        state: &AppState,
    ) -> Result<Self, Self::Rejection> {
        let Some(pool) = state.db.as_ref() else {
            return Err(error_response(
                StatusCode::SERVICE_UNAVAILABLE,
                "auth_unavailable",
            ));
        };
        match authenticated_user(pool, state, &parts.headers).await {
            Ok(Some(user)) if user.role.eq_ignore_ascii_case("admin") => Ok(Self(user)),
            Ok(Some(_)) => Err(error_response(StatusCode::FORBIDDEN, "admin_required")),
            Ok(None) => {
                state.metrics.record_auth_failure();
                Err(error_response(StatusCode::UNAUTHORIZED, "unauthenticated"))
            }
            Err(error) => {
                tracing::error!(?error, "admin user lookup failed");
                Err(error_response(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "auth_unavailable",
                ))
            }
        }
    }
}

#[allow(dead_code)]
#[derive(Debug, Clone, Copy)]
pub(crate) struct ServiceRequest;

#[axum::async_trait]
impl FromRequestParts<AppState> for ServiceRequest {
    type Rejection = Response;

    async fn from_request_parts(
        parts: &mut Parts,
        state: &AppState,
    ) -> Result<Self, Self::Rejection> {
        let Some(expected) = state.auth.service_token.as_deref() else {
            return Err(error_response(
                StatusCode::SERVICE_UNAVAILABLE,
                "service_auth_unavailable",
            ));
        };
        let Some(provided) = parts
            .headers
            .get("x-ashat-service-token")
            .and_then(|value| value.to_str().ok())
        else {
            return Err(error_response(
                StatusCode::UNAUTHORIZED,
                "service_unauthenticated",
            ));
        };
        if !constant_time_equal(provided, expected) {
            return Err(error_response(
                StatusCode::UNAUTHORIZED,
                "service_unauthenticated",
            ));
        }
        Ok(Self)
    }
}

#[derive(Debug, FromRow)]
struct UserWithPassword {
    id: String,
    username: String,
    email: String,
    password_hash: String,
    display_name: String,
    role: String,
    is_active: i8,
}

#[derive(Debug, FromRow)]
struct RustSessionUser {
    id: String,
    username: String,
    email: String,
    display_name: String,
    role: String,
    csrf_hash: String,
}

#[derive(Debug, FromRow)]
struct LegacySessionUser {
    id: String,
    username: String,
    email: String,
    display_name: String,
    role: String,
}

#[derive(Debug)]
enum SessionKind {
    Rust { csrf_hash: String },
    Legacy,
}

#[derive(Debug)]
struct CurrentAuth {
    user: PublicUser,
    kind: SessionKind,
}

pub(crate) fn routes() -> Router<AppState> {
    Router::new()
        .route("/api/auth/session", get(session))
        .route("/api/auth/me", get(me))
        .route("/api/me", get(me))
        .route("/api/auth/login", post(login))
        .route("/api/auth/register", post(register))
        .route("/api/auth/logout", post(logout))
        .route("/api/account", get(account).put(update_account))
}

async fn login(
    State(state): State<AppState>,
    ConnectInfo(peer): ConnectInfo<SocketAddr>,
    headers: HeaderMap,
    Json(input): Json<LoginRequest>,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
    };

    let identifier = input.identifier.trim();
    if identifier.is_empty() || identifier.len() > 254 || input.password.is_empty() {
        return error_response(StatusCode::BAD_REQUEST, "invalid_credentials");
    }

    let rate_key = format!(
        "login:{}:{}",
        client_key(&headers, &state.auth, peer),
        identifier.to_ascii_lowercase()
    );
    if let Some(retry_after) = state
        .auth_rate_limiter
        .check(&rate_key, 10, Duration::from_secs(60))
    {
        state.metrics.record_rate_limited();
        return rate_limit_response(retry_after);
    }

    let user = match sqlx::query_as::<_, UserWithPassword>(
        "SELECT id, username, email, password_hash, display_name, role, is_active
         FROM users WHERE username = ? OR email = ? LIMIT 1",
    )
    .bind(identifier)
    .bind(identifier)
    .fetch_optional(pool)
    .await
    {
        Ok(user) => user,
        Err(error) => {
            tracing::error!(?error, "authentication user lookup failed");
            return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
        }
    };

    let Some(user) = user else {
        state.metrics.record_auth_failure();
        tracing::warn!(
            event = "auth.login.failure",
            request_id = %request_id(&headers),
            reason = "invalid_credentials"
        );
        return error_response(StatusCode::UNAUTHORIZED, "invalid_credentials");
    };
    if user.is_active == 0 {
        state.metrics.record_auth_failure();
        tracing::warn!(
            event = "auth.login.failure",
            request_id = %request_id(&headers),
            reason = "inactive_account"
        );
        return error_response(StatusCode::UNAUTHORIZED, "invalid_credentials");
    }
    let password = input.password;
    // PHP password_hash(PASSWORD_BCRYPT) commonly emits $2y$. The Rust
    // bcrypt implementation accepts the equivalent $2b$ prefix.
    let password_hash = user.password_hash.replace("$2y$", "$2b$");
    let valid =
        tokio::task::spawn_blocking(move || verify(password, &password_hash).unwrap_or(false))
            .await
            .unwrap_or(false);
    if !valid {
        state.metrics.record_auth_failure();
        tracing::warn!(
            event = "auth.login.failure",
            request_id = %request_id(&headers),
            reason = "invalid_credentials"
        );
        return error_response(StatusCode::UNAUTHORIZED, "invalid_credentials");
    }

    tracing::info!(
        event = "auth.login.success",
        request_id = %request_id(&headers),
        user_id = %user.id
    );
    state.auth_rate_limiter.clear(&rate_key);
    let session_token = new_token();
    let csrf_token = new_token();
    let session_hash = hash_token(&session_token);
    let csrf_hash = hash_token(&csrf_token);
    let lifetime = state.auth.session_lifetime_seconds;

    if let Err(error) = sqlx::query(
        "INSERT INTO rust_sessions
            (session_hash, user_id, csrf_hash, ip, user_agent, created_at, last_seen, expires_at)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))",
    )
    .bind(&session_hash)
    .bind(&user.id)
    .bind(&csrf_hash)
    .bind(client_key(&headers, &state.auth, peer))
    .bind(headers.get(header::USER_AGENT).and_then(|value| value.to_str().ok()).map(|value| value.to_owned()))
    .bind(lifetime)
    .execute(pool)
    .await
    {
        tracing::error!(?error, "Rust session creation failed");
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "session_unavailable");
    }

    let response = Json(LoginResponse {
        authenticated: true,
        user: public_user(&user),
        csrf: csrf_token.clone(),
    })
    .into_response();
    with_auth_cookies(response, &state, &session_token, &csrf_token, lifetime)
}

async fn register(
    State(state): State<AppState>,
    ConnectInfo(peer): ConnectInfo<SocketAddr>,
    headers: HeaderMap,
    Json(input): Json<RegisterRequest>,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
    };
    let rate_key = format!("register:{}", client_key(&headers, &state.auth, peer));
    if let Some(retry_after) =
        state
            .auth_rate_limiter
            .check(&rate_key, 5, Duration::from_secs(3600))
    {
        return rate_limit_response(retry_after);
    }
    if state.auth.email_verification_enabled {
        return error_response(
            StatusCode::SERVICE_UNAVAILABLE,
            "email_verification_not_configured",
        );
    }
    let username = input.username.trim();
    let email = input.email.trim();
    let display_name = input.display_name.as_deref().unwrap_or(username).trim();
    if username.len() < 3
        || username.len() > 30
        || !username
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_')
        || email.len() > 255
        || !email.contains('@')
        || input.password.len() < 8
        || display_name.is_empty()
        || display_name.chars().count() > 200
    {
        return error_response(StatusCode::BAD_REQUEST, "invalid_registration");
    }
    let exists =
        sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM users WHERE username=? OR email=?")
            .bind(username)
            .bind(email)
            .fetch_one(pool)
            .await
            .unwrap_or(1);
    if exists != 0 {
        return error_response(StatusCode::CONFLICT, "username_or_email_taken");
    }
    let password_hash =
        match tokio::task::spawn_blocking(move || hash(input.password, DEFAULT_COST)).await {
            Ok(Ok(value)) => value,
            _ => {
                return error_response(StatusCode::SERVICE_UNAVAILABLE, "registration_unavailable")
            }
        };
    let id = Uuid::new_v4().to_string();
    if let Err(error) = sqlx::query("INSERT INTO users (id,username,email,password_hash,display_name,role,is_active) VALUES (?,?,?,? ,?,'Member',1)")
        .bind(&id).bind(username).bind(email).bind(password_hash).bind(display_name).execute(pool).await {
        tracing::error!(?error, "registration insert failed");
        return error_response(StatusCode::CONFLICT, "username_or_email_taken");
    }
    tracing::info!(
        event = "auth.register.success",
        request_id = %request_id(&headers),
        user_id = %id
    );
    (StatusCode::CREATED, Json(serde_json::json!({"registered": true, "verification_required": false, "user": {"id": id, "username": username, "email": email, "display_name": display_name, "role": "Member"}}))).into_response()
}

async fn session(State(state): State<AppState>, headers: HeaderMap) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
    };

    let current = match current_auth(pool, &state, &headers).await {
        Ok(current) => current,
        Err(error) => {
            tracing::error!(?error, "session lookup failed");
            return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
        }
    };

    let Some(current) = current else {
        return Json(AuthResponse {
            authenticated: false,
            user: None,
            csrf: None,
        })
        .into_response();
    };

    let csrf_cookie_name = &state.auth.csrf_cookie_name;
    let csrf = cookie_value(&headers, csrf_cookie_name).unwrap_or_else(new_token);
    let mut response = Json(AuthResponse {
        authenticated: true,
        user: Some(current.user),
        csrf: Some(csrf.clone()),
    })
    .into_response();

    if cookie_value(&headers, csrf_cookie_name).is_none() {
        if let SessionKind::Rust { .. } = current.kind {
            // The token is tied to the Rust session when a new CSRF cookie is issued.
            if let Err(error) = update_csrf(pool, &headers, &state, &csrf).await {
                tracing::warn!(?error, "unable to initialize Rust CSRF token");
            }
        }
        append_cookie(
            &mut response,
            csrf_cookie_name,
            &csrf,
            0,
            false,
            state.auth.secure_cookie,
        );
    }
    response
}

async fn me(State(state): State<AppState>, headers: HeaderMap) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
    };
    match current_auth(pool, &state, &headers).await {
        Ok(Some(current)) => Json(serde_json::json!({
            "authenticated": true,
            "user": current.user,
        }))
        .into_response(),
        Ok(None) => error_response(StatusCode::UNAUTHORIZED, "unauthenticated"),
        Err(error) => {
            tracing::error!(?error, "authenticated user lookup failed");
            error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable")
        }
    }
}

async fn account(State(state): State<AppState>, headers: HeaderMap) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
    };
    match current_auth(pool, &state, &headers).await {
        Ok(Some(current)) => Json(serde_json::json!({"user": current.user})).into_response(),
        Ok(None) => error_response(StatusCode::UNAUTHORIZED, "unauthenticated"),
        Err(error) => {
            tracing::error!(?error, "account lookup failed");
            error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable")
        }
    }
}

async fn update_account(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(input): Json<ProfileUpdate>,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
    };
    let Some(current) = current_auth(pool, &state, &headers).await.ok().flatten() else {
        return error_response(StatusCode::UNAUTHORIZED, "unauthenticated");
    };
    if !csrf_is_valid(&headers, &state, &current.kind) {
        return error_response(StatusCode::FORBIDDEN, "csrf_failed");
    }
    let display_name = input
        .display_name
        .unwrap_or(current.user.display_name.clone());
    let email = input.email.unwrap_or(current.user.email.clone());
    if display_name.trim().is_empty()
        || display_name.chars().count() > 200
        || email.len() > 255
        || !email.contains('@')
    {
        return error_response(StatusCode::BAD_REQUEST, "invalid_profile");
    }
    let duplicate =
        sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM users WHERE email=? AND id<>?")
            .bind(&email)
            .bind(&current.user.id)
            .fetch_one(pool)
            .await
            .unwrap_or(1);
    if duplicate != 0 {
        return error_response(StatusCode::CONFLICT, "email_taken");
    }
    if let Err(error) =
        sqlx::query("UPDATE users SET display_name=?,email=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
            .bind(display_name.trim())
            .bind(email.trim())
            .bind(&current.user.id)
            .execute(pool)
            .await
    {
        tracing::error!(?error, "profile update failed");
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "profile_unavailable");
    }
    match sqlx::query_as::<_, UserWithPassword>(
        "SELECT id,username,email,password_hash,display_name,role,is_active FROM users WHERE id=?",
    )
    .bind(&current.user.id)
    .fetch_optional(pool)
    .await
    {
        Ok(Some(user)) => Json(serde_json::json!({"user": public_user(&user)})).into_response(),
        Ok(None) => error_response(StatusCode::NOT_FOUND, "user_not_found"),
        Err(error) => {
            tracing::error!(?error, "updated profile lookup failed");
            error_response(StatusCode::SERVICE_UNAVAILABLE, "profile_unavailable")
        }
    }
}

async fn logout(State(state): State<AppState>, headers: HeaderMap) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
    };

    let current = match current_auth(pool, &state, &headers).await {
        Ok(current) => current,
        Err(error) => {
            tracing::error!(?error, "logout session lookup failed");
            return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
        }
    };

    if let Some(current) = current {
        if !csrf_is_valid(&headers, &state, &current.kind) {
            return error_response(StatusCode::FORBIDDEN, "csrf_failed");
        }
        tracing::info!(
            event = "auth.logout",
            request_id = %request_id(&headers),
            user_id = %current.user.id
        );
        match current.kind {
            SessionKind::Rust { .. } => {
                if let Some(token) = cookie_value(&headers, &state.auth.cookie_name) {
                    let _ = sqlx::query("DELETE FROM rust_sessions WHERE session_hash = ?")
                        .bind(hash_token(&token))
                        .execute(pool)
                        .await;
                }
            }
            SessionKind::Legacy => {
                if let Some(token) = cookie_value(&headers, &state.auth.legacy_cookie_name) {
                    let _ = sqlx::query("DELETE FROM sessions WHERE id = ?")
                        .bind(token)
                        .execute(pool)
                        .await;
                }
            }
        }
    }

    let mut response = Json(OkResponse { ok: true }).into_response();
    clear_cookie(
        &mut response,
        &state.auth.cookie_name,
        state.auth.secure_cookie,
    );
    clear_cookie(
        &mut response,
        &state.auth.csrf_cookie_name,
        state.auth.secure_cookie,
    );
    response
}

pub(crate) async fn enforce_csrf(
    state: &AppState,
    method: &Method,
    path: &str,
    headers: &HeaderMap,
) -> Result<(), Response> {
    if !matches!(method, &Method::POST | &Method::PUT | &Method::DELETE)
        || matches!(
            path,
            "/api/auth/login" | "/api/auth/register" | "/api/sso/verify-session"
            | "/api/v1/auth/login" | "/api/v1/auth/status" | "/api/v1/auth/logout"
            | "/api/v1/models/announce"
            // Icarus device flow: the CLI has no session cookie when it
            // starts device auth or validates a token by body, and the
            // device login page POSTs a username/password form.
            | "/api/icarus/auth/device" | "/api/icarus/auth/validate"
            | "/api/icarus/auth/login"
            // OIDC token endpoint: called server-to-server by Paws & Parcels.
            | "/api/oauth/token" | "/api/oauth/authorize"
        )
    {
        return Ok(());
    }
    let Some(pool) = state.db.as_ref() else {
        return Err(error_response(
            StatusCode::SERVICE_UNAVAILABLE,
            "auth_unavailable",
        ));
    };
    let current = match current_auth(pool, state, headers).await {
        Ok(Some(current)) => current,
        Ok(None) => {
            state.metrics.record_auth_failure();
            return Err(error_response(StatusCode::UNAUTHORIZED, "unauthenticated"));
        }
        Err(error) => {
            tracing::error!(?error, "CSRF session lookup failed");
            return Err(error_response(
                StatusCode::SERVICE_UNAVAILABLE,
                "auth_unavailable",
            ));
        }
    };
    if !csrf_is_valid(headers, state, &current.kind) {
        return Err(error_response(StatusCode::FORBIDDEN, "csrf_failed"));
    }
    Ok(())
}

pub(crate) async fn authenticated_user(
    pool: &MySqlPool,
    state: &AppState,
    headers: &HeaderMap,
) -> Result<Option<PublicUser>, sqlx::Error> {
    Ok(current_auth(pool, state, headers)
        .await?
        .map(|current| current.user))
}

async fn current_auth(
    pool: &MySqlPool,
    state: &AppState,
    headers: &HeaderMap,
) -> Result<Option<CurrentAuth>, sqlx::Error> {
    if let Some(token) = cookie_value(headers, &state.auth.cookie_name) {
        let row = sqlx::query_as::<_, RustSessionUser>(
            "SELECT u.id, u.username, u.email, u.display_name, u.role, s.csrf_hash
             FROM rust_sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.session_hash = ? AND s.expires_at > UTC_TIMESTAMP() AND u.is_active = 1
             LIMIT 1",
        )
        .bind(hash_token(&token))
        .fetch_optional(pool)
        .await?;
        if let Some(row) = row {
            let _ = sqlx::query(
                "UPDATE rust_sessions SET last_seen = UTC_TIMESTAMP() WHERE session_hash = ?",
            )
            .bind(hash_token(&token))
            .execute(pool)
            .await;
            return Ok(Some(CurrentAuth {
                user: PublicUser {
                    id: row.id,
                    username: row.username,
                    email: row.email,
                    display_name: row.display_name,
                    role: row.role,
                },
                kind: SessionKind::Rust {
                    csrf_hash: row.csrf_hash,
                },
            }));
        }
    }

    // Existing PHP logins remain readable during the migration. PHP stores
    // the session ID in the shared sessions table, so Rust can authenticate
    // the same browser without reading PHP's serialized session files.
    if let Some(token) = cookie_value(headers, &state.auth.legacy_cookie_name) {
        let row = sqlx::query_as::<_, LegacySessionUser>(
            "SELECT u.id, u.username, u.email, u.display_name, u.role
             FROM sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.expires_at > UTC_TIMESTAMP() AND u.is_active = 1
             LIMIT 1",
        )
        .bind(token)
        .fetch_optional(pool)
        .await?;
        if let Some(row) = row {
            return Ok(Some(CurrentAuth {
                user: PublicUser {
                    id: row.id,
                    username: row.username,
                    email: row.email,
                    display_name: row.display_name,
                    role: row.role,
                },
                kind: SessionKind::Legacy,
            }));
        }
    }

    Ok(None)
}

async fn update_csrf(
    pool: &MySqlPool,
    headers: &HeaderMap,
    state: &AppState,
    csrf: &str,
) -> Result<(), sqlx::Error> {
    let Some(session) = cookie_value(headers, &state.auth.cookie_name) else {
        return Ok(());
    };
    sqlx::query("UPDATE rust_sessions SET csrf_hash = ? WHERE session_hash = ?")
        .bind(hash_token(csrf))
        .bind(hash_token(&session))
        .execute(pool)
        .await
        .map(|_| ())
}

fn csrf_is_valid(headers: &HeaderMap, state: &AppState, kind: &SessionKind) -> bool {
    let Some(header_token) = headers
        .get("x-csrf-token")
        .and_then(|value| value.to_str().ok())
    else {
        return false;
    };
    let Some(cookie_token) = cookie_value(headers, &state.auth.csrf_cookie_name) else {
        return false;
    };
    if header_token.is_empty() || header_token != cookie_token {
        return false;
    }
    match kind {
        SessionKind::Rust { csrf_hash } => {
            constant_time_equal(&hash_token(header_token), csrf_hash)
        }
        // Legacy PHP sessions do not have a Rust CSRF hash. The double-submit
        // cookie is still required for Rust logout while the bridge is active.
        SessionKind::Legacy => true,
    }
}

fn public_user(user: &UserWithPassword) -> PublicUser {
    PublicUser {
        id: user.id.clone(),
        username: user.username.clone(),
        email: user.email.clone(),
        display_name: user.display_name.clone(),
        role: user.role.clone(),
    }
}

pub(crate) fn new_token() -> String {
    let suffix: String = rand::thread_rng()
        .sample_iter(&Alphanumeric)
        .take(32)
        .map(char::from)
        .collect();
    format!("{}{}", Uuid::new_v4().simple(), suffix)
}

pub(crate) fn hash_token(token: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(token.as_bytes());
    format!("{:x}", hasher.finalize())
}

fn constant_time_equal(left: &str, right: &str) -> bool {
    if left.len() != right.len() {
        return false;
    }
    left.as_bytes()
        .iter()
        .zip(right.as_bytes())
        .fold(0u8, |acc, (a, b)| acc | (a ^ b))
        == 0
}

pub(crate) fn cookie_value(headers: &HeaderMap, name: &str) -> Option<String> {
    let raw = headers.get(header::COOKIE)?.to_str().ok()?;
    raw.split(';').find_map(|part| {
        let (key, value) = part.trim().split_once('=')?;
        (key == name).then(|| value.to_owned())
    })
}

pub(crate) fn with_auth_cookies(
    mut response: Response,
    state: &AppState,
    session: &str,
    csrf: &str,
    lifetime: i64,
) -> Response {
    append_cookie(
        &mut response,
        &state.auth.cookie_name,
        session,
        lifetime,
        true,
        state.auth.secure_cookie,
    );
    append_cookie(
        &mut response,
        &state.auth.csrf_cookie_name,
        csrf,
        0,
        false,
        state.auth.secure_cookie,
    );
    response
}

fn append_cookie(
    response: &mut Response,
    name: &str,
    value: &str,
    max_age: i64,
    http_only: bool,
    secure: bool,
) {
    let mut cookie = format!("{}={}; Path=/; SameSite=Lax", name, value);
    if max_age != 0 {
        cookie.push_str(&format!("; Max-Age={}", max_age));
    }
    if http_only {
        cookie.push_str("; HttpOnly");
    }
    if secure {
        cookie.push_str("; Secure");
    }
    if let Ok(header_value) = HeaderValue::from_str(&cookie) {
        response
            .headers_mut()
            .append(header::SET_COOKIE, header_value);
    }
}

fn clear_cookie(response: &mut Response, name: &str, secure: bool) {
    append_cookie(response, name, "", -1, true, secure);
}

fn request_id(headers: &HeaderMap) -> &str {
    headers
        .get("x-request-id")
        .and_then(|value| value.to_str().ok())
        .unwrap_or("unknown")
}

fn client_key(headers: &HeaderMap, auth: &crate::AuthConfig, peer: SocketAddr) -> String {
    if auth.trust_proxy_headers {
        for name in ["x-real-ip", "x-forwarded-for"] {
            if let Some(value) = headers.get(name).and_then(|value| value.to_str().ok()) {
                let candidate = value.split(',').next().unwrap_or_default().trim();
                if !candidate.is_empty() && candidate.len() <= 64 && candidate.is_ascii() {
                    return candidate.to_owned();
                }
            }
        }
    }
    peer.ip().to_string()
}

#[cfg(test)]
mod tests {
    use super::{constant_time_equal, hash_token, new_token};

    #[test]
    fn tokens_are_random_and_hashes_are_stable() {
        let first = new_token();
        let second = new_token();
        assert_ne!(first, second);
        assert_eq!(hash_token(&first), hash_token(&first));
        assert_ne!(hash_token(&first), hash_token(&second));
    }

    #[test]
    fn constant_time_comparison_requires_equal_values() {
        assert!(constant_time_equal("abc", "abc"));
        assert!(!constant_time_equal("abc", "abd"));
        assert!(!constant_time_equal("abc", "abcd"));
    }
}
