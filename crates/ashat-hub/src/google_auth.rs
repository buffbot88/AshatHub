use std::time::Duration;

use axum::{
    extract::{Query, State},
    http::{header, StatusCode},
    response::{IntoResponse, Redirect, Response},
    routing::{get, post},
    Json, Router,
};
use serde::{Deserialize, Serialize};
use sqlx::{FromRow, MySqlPool};
use uuid::Uuid;

use crate::{response::error_response, AppState};

/// Google OAuth configuration loaded from environment.
#[derive(Debug, Clone)]
pub(crate) struct GoogleOAuthConfig {
    pub(crate) client_id: String,
    pub(crate) client_secret: String,
    pub(crate) redirect_uri: String,
}

impl GoogleOAuthConfig {
    pub(crate) fn from_env() -> Option<Self> {
        let client_id = std::env::var("GOOGLE_CLIENT_ID").ok()?;
        let client_secret = std::env::var("GOOGLE_CLIENT_SECRET").ok()?;
        let redirect_uri = std::env::var("GOOGLE_REDIRECT_URI")
            .unwrap_or_else(|_| "http://localhost:3000/api/auth/google/callback".to_owned());
        Some(Self {
            client_id,
            client_secret,
            redirect_uri,
        })
    }

    pub(crate) fn is_configured(&self) -> bool {
        !self.client_id.is_empty() && !self.client_secret.is_empty()
    }
}

#[derive(Debug, Deserialize)]
pub(crate) struct GoogleCallbackParams {
    pub(crate) code: Option<String>,
    pub(crate) state: Option<String>,
    pub(crate) error: Option<String>,
}

#[derive(Debug, Deserialize)]
struct GoogleLoginQuery {
    /// Optional same-origin path to redirect to after a successful login.
    /// Used by OIDC authorize and the Vesper desktop authorization page.
    next: Option<String>,
}

#[derive(Debug, Deserialize)]
pub(crate) struct GoogleTokenResponse {
    pub(crate) access_token: String,
    #[allow(dead_code)]
    token_type: String,
    #[allow(dead_code)]
    expires_in: u64,
    #[allow(dead_code)]
    id_token: Option<String>,
}

#[derive(Debug, Deserialize)]
pub(crate) struct GoogleUserInfo {
    #[serde(alias = "sub")]
    pub(crate) id: String,
    pub(crate) email: String,
    name: Option<String>,
    picture: Option<String>,
    #[serde(alias = "email_verified")]
    #[allow(dead_code)]
    verified_email: Option<bool>,
}

#[derive(Debug, FromRow)]
struct LinkedGoogleAccount {
    #[allow(dead_code)]
    id: String,
    user_id: String,
    #[allow(dead_code)]
    google_id: String,
}

#[derive(Debug, Serialize)]
struct GoogleAuthStatus {
    configured: bool,
    linked: bool,
    google_email: Option<String>,
}

fn now_unix() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

pub(crate) fn routes() -> Router<AppState> {
    Router::new()
        .route("/api/auth/google", get(google_login))
        .route("/api/auth/google/callback", get(google_callback))
        .route("/api/auth/google/link", post(google_link))
        .route("/api/auth/google/unlink", post(google_unlink))
        .route("/api/auth/google/status", get(google_status))
}

/// Redirect the user to Google's OAuth consent screen.
async fn google_login(State(state): State<AppState>, Query(query): Query<GoogleLoginQuery>) -> Response {
    let Some(oauth) = &state.google_oauth else {
        return error_response(
            StatusCode::SERVICE_UNAVAILABLE,
            "google_auth_not_configured",
        );
    };
    if !oauth.is_configured() {
        return error_response(
            StatusCode::SERVICE_UNAVAILABLE,
            "google_auth_not_configured",
        );
    }

    // Only accept same-origin relative redirect targets (no open redirect).
    let next = query
        .next
        .filter(|value| {
            value.starts_with('/')
                && !value.starts_with("//")
                && value.len() <= 2048
                && !value.contains('\\')
        });

    let state_token = crate::auth::new_token();
    let auth_url = format!(
        "https://accounts.google.com/o/oauth2/v2/auth?\
         client_id={}&\
         redirect_uri={}&\
         response_type=code&\
         scope=openid%20email%20profile&\
         access_type=offline&\
         prompt=consent&\
         state={}",
        urlencoding::encode(&oauth.client_id),
        urlencoding::encode(&oauth.redirect_uri),
        urlencoding::encode(&state_token),
    );

    let mut response = Redirect::temporary(&auth_url).into_response();
    // Store the state token server-side (TTL 10 min). Validation on the
    // callback uses this store, so it works regardless of cookie Domain or
    // port scoping (proxies and different callback ports were silently
    // dropping the cookie and producing `invalid_state`).
    state
        .oauth_states
        .lock()
        .unwrap()
        .insert(state_token.clone(), (now_unix() + 600, next));
    // Also set the cookie for CSRF parity / fallback validation.
    let cookie = format!(
        "ashat_google_state={}; Path=/; SameSite=Lax; Max-Age=600; HttpOnly",
        state_token
    );
    if let Ok(header_value) = axum::http::HeaderValue::from_str(&cookie) {
        response
            .headers_mut()
            .append(header::SET_COOKIE, header_value);
    }
    response
}

/// Handle the OAuth callback from Google.
async fn google_callback(
    State(state): State<AppState>,
    Query(params): Query<GoogleCallbackParams>,
    headers: axum::http::HeaderMap,
) -> Response {
    let Some(oauth) = &state.google_oauth else {
        return error_response(
            StatusCode::SERVICE_UNAVAILABLE,
            "google_auth_not_configured",
        );
    };

    // Check for error from Google.
    if let Some(error) = &params.error {
        tracing::warn!(?error, "Google OAuth returned an error");
        return Redirect::temporary(&format!(
            "/?google_error={}",
            urlencoding::encode(error)
        ))
        .into_response();
    }

    let Some(code) = &params.code else {
        return error_response(StatusCode::BAD_REQUEST, "missing_auth_code");
    };

    let Some(state_param) = &params.state else {
        return error_response(StatusCode::FORBIDDEN, "invalid_state");
    };
    // Validate against the server-side store first (robust across cookie
    // Domain/port issues), falling back to cookie comparison for old flows.
    let next_target = {
        let mut guard = state.oauth_states.lock().unwrap();
        match guard.remove(state_param) {
            Some((expiry, next)) if expiry > now_unix() => next,
            _ => None,
        }
    };
    let cookie_valid = crate::auth::cookie_value(&headers, "ashat_google_state")
        .map(|cookie_token| &cookie_token == state_param)
        .unwrap_or(false);
    if next_target.is_none() && !cookie_valid {
        return error_response(StatusCode::FORBIDDEN, "invalid_state");
    }

    // Exchange authorization code for tokens.
    let token_response = match exchange_code(oauth, code).await {
        Ok(response) => response,
        Err(error) => {
            tracing::error!(?error, "Failed to exchange Google auth code");
            return error_response(
                StatusCode::BAD_GATEWAY,
                "google_token_exchange_failed",
            );
        }
    };

    // Fetch user info from Google.
    let google_user = match fetch_google_user(&token_response.access_token).await {
        Ok(user) => user,
        Err(error) => {
            tracing::error!(?error, "Failed to fetch Google user info");
            return error_response(
                StatusCode::BAD_GATEWAY,
                "google_userinfo_failed",
            );
        }
    };

    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
    };

    // Check if there's an existing session (user is trying to link).
    let existing_user = crate::auth::authenticated_user(pool, &state, &headers)
        .await
        .ok()
        .flatten();

    // Check if this Google account is already linked.
    let linked = sqlx::query_as::<_, LinkedGoogleAccount>(
        "SELECT id, user_id, google_id FROM google_accounts WHERE google_id = ?",
    )
    .bind(&google_user.id)
    .fetch_optional(pool)
    .await;

    let next_target = next_target.unwrap_or_else(|| "/".to_owned());
    match linked {
        Ok(Some(account)) => {
            // Already linked — log in as that user.
            create_session_and_redirect(pool, &state, &account.user_id, &next_target).await
        }
        Ok(None) => {
            // Not linked yet.
            if let Some(user) = existing_user {
                // User is logged in — link this Google account to them.
                link_google_to_user(pool, &user.id, &google_user).await;
                Redirect::temporary("/?google_linked=1").into_response()
            } else {
                // Try to find existing user by email.
                if let Some(user_id) =
                    find_user_by_email(pool, &google_user.email).await
                {
                    // Auto-link and log in.
                    link_google_to_user(pool, &user_id, &google_user).await;
                    create_session_and_redirect(pool, &state, &user_id, &next_target).await
                } else {
                    // Create a new user account.
                    let user_id = create_user_from_google(pool, &google_user).await;
                    link_google_to_user(pool, &user_id, &google_user).await;
                    create_session_and_redirect(pool, &state, &user_id, &next_target).await
                }
            }
        }
        Err(error) => {
            tracing::error!(?error, "Google account lookup failed");
            error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable")
        }
    }
}

/// Link Google to the currently authenticated user.
async fn google_link(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Response {
    let Some(oauth) = &state.google_oauth else {
        return error_response(
            StatusCode::SERVICE_UNAVAILABLE,
            "google_auth_not_configured",
        );
    };
    if !oauth.is_configured() {
        return error_response(
            StatusCode::SERVICE_UNAVAILABLE,
            "google_auth_not_configured",
        );
    }

    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
    };
    let Some(user) = crate::auth::authenticated_user(pool, &state, &headers)
        .await
        .ok()
        .flatten()
    else {
        return error_response(StatusCode::UNAUTHORIZED, "unauthenticated");
    };

    // Check if user already has a linked Google account.
    let existing = sqlx::query_scalar::<_, String>(
        "SELECT google_id FROM google_accounts WHERE user_id = ?",
    )
    .bind(&user.id)
    .fetch_optional(pool)
    .await;

    if let Ok(Some(_)) = existing {
        return error_response(StatusCode::CONFLICT, "google_already_linked");
    }

    // Redirect to Google OAuth with a link-specific state.
    let state_token = format!("link:{}", crate::auth::new_token());
    let auth_url = format!(
        "https://accounts.google.com/o/oauth2/v2/auth?\
         client_id={}&\
         redirect_uri={}&\
         response_type=code&\
         scope=openid%20email%20profile&\
         access_type=offline&\
         prompt=consent&\
         state={}",
        urlencoding::encode(&oauth.client_id),
        urlencoding::encode(&oauth.redirect_uri),
        urlencoding::encode(&state_token),
    );

    let mut response = Redirect::temporary(&auth_url).into_response();
    state
        .oauth_states
        .lock()
        .unwrap()
        .insert(state_token.clone(), (now_unix() + 600, None));
    let cookie = format!(
        "ashat_google_state={}; Path=/; SameSite=Lax; Max-Age=600; HttpOnly",
        state_token
    );
    if let Ok(header_value) = axum::http::HeaderValue::from_str(&cookie) {
        response
            .headers_mut()
            .append(header::SET_COOKIE, header_value);
    }
    response
}

/// Unlink Google account from the currently authenticated user.
async fn google_unlink(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "auth_unavailable");
    };
    let Some(user) = crate::auth::authenticated_user(pool, &state, &headers)
        .await
        .ok()
        .flatten()
    else {
        return error_response(StatusCode::UNAUTHORIZED, "unauthenticated");
    };

    match sqlx::query("DELETE FROM google_accounts WHERE user_id = ?")
        .bind(&user.id)
        .execute(pool)
        .await
    {
        Ok(_) => Json(serde_json::json!({"ok": true})).into_response(),
        Err(error) => {
            tracing::error!(?error, "Failed to unlink Google account");
            error_response(
                StatusCode::SERVICE_UNAVAILABLE,
                "unlink_failed",
            )
        }
    }
}

/// Return Google auth status for the current user.
async fn google_status(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Response {
    let configured = state
        .google_oauth
        .as_ref()
        .is_some_and(|oauth| oauth.is_configured());

    let Some(pool) = state.db.as_ref() else {
        return Json(GoogleAuthStatus {
            configured,
            linked: false,
            google_email: None,
        })
        .into_response();
    };

    let user = crate::auth::authenticated_user(pool, &state, &headers)
        .await
        .ok()
        .flatten();

    let (linked, google_email) = if let Some(user) = &user {
        let result = sqlx::query_as::<_, (String, String)>(
            "SELECT google_id, email FROM google_accounts WHERE user_id = ?",
        )
        .bind(&user.id)
        .fetch_optional(pool)
        .await;

        match result {
            Ok(Some((_, email))) => (true, Some(email)),
            _ => (false, None),
        }
    } else {
        (false, None)
    };

    Json(GoogleAuthStatus {
        configured,
        linked,
        google_email,
    })
    .into_response()
}

// ─── helpers ───────────────────────────────────────────────────────

pub(crate) async fn exchange_code(
    oauth: &GoogleOAuthConfig,
    code: &str,
) -> Result<GoogleTokenResponse, reqwest::Error> {
    let client = reqwest::Client::builder()
        .timeout(Duration::from_secs(15))
        .build()?;

    let params = [
        ("code", code),
        ("client_id", &oauth.client_id),
        ("client_secret", &oauth.client_secret),
        ("redirect_uri", &oauth.redirect_uri),
        ("grant_type", "authorization_code"),
    ];

    client
        .post("https://oauth2.googleapis.com/token")
        .form(&params)
        .send()
        .await?
        .json::<GoogleTokenResponse>()
        .await
}

pub(crate) async fn fetch_google_user(access_token: &str) -> Result<GoogleUserInfo, reqwest::Error> {
    let client = reqwest::Client::builder()
        .timeout(Duration::from_secs(10))
        .build()?;

    client
        .get("https://www.googleapis.com/oauth2/v3/userinfo")
        .bearer_auth(access_token)
        .send()
        .await?
        .json::<GoogleUserInfo>()
        .await
}

pub(crate) async fn find_user_by_email(pool: &MySqlPool, email: &str) -> Option<String> {
    sqlx::query_scalar::<_, String>("SELECT id FROM users WHERE email = ? LIMIT 1")
        .bind(email)
        .fetch_optional(pool)
        .await
        .ok()
        .flatten()
}

pub(crate) async fn create_user_from_google(pool: &MySqlPool, google_user: &GoogleUserInfo) -> String {
    let id = Uuid::new_v4().to_string();
    let username = format!("google_{}", &google_user.id[..8.min(google_user.id.len())]);
    let display_name = google_user
        .name
        .clone()
        .unwrap_or_else(|| username.clone());

    let _ = sqlx::query(
        "INSERT INTO users (id, username, email, password_hash, display_name, role, is_active)
         VALUES (?, ?, ?, '', ?, 'Member', 1)
         ON DUPLICATE KEY UPDATE id = id",
    )
    .bind(&id)
    .bind(&username)
    .bind(&google_user.email)
    .bind(&display_name)
    .execute(pool)
    .await;

    id
}

pub(crate) async fn link_google_to_user(pool: &MySqlPool, user_id: &str, google_user: &GoogleUserInfo) {
    let id = Uuid::new_v4().to_string();
    let _ = sqlx::query(
        "INSERT INTO google_accounts (id, user_id, google_id, email, name, avatar_url)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE email = VALUES(email), name = VALUES(name), avatar_url = VALUES(avatar_url)",
    )
    .bind(&id)
    .bind(user_id)
    .bind(&google_user.id)
    .bind(&google_user.email)
    .bind(google_user.name.as_deref().unwrap_or(""))
    .bind(google_user.picture.as_deref().unwrap_or(""))
    .execute(pool)
    .await;
}

async fn create_session_and_redirect(
    pool: &MySqlPool,
    state: &AppState,
    user_id: &str,
    redirect_to: &str,
) -> Response {
    use crate::auth::{hash_token, new_token};

    let session_token = new_token();
    let csrf_token = new_token();
    let session_hash = hash_token(&session_token);
    let csrf_hash = hash_token(&csrf_token);
    let lifetime = state.auth.session_lifetime_seconds;

    let _ = sqlx::query(
        "INSERT INTO rust_sessions
            (session_hash, user_id, csrf_hash, ip, user_agent, created_at, last_seen, expires_at)
         VALUES (?, ?, ?, 'google-oauth', 'google-oauth', UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))",
    )
    .bind(&session_hash)
    .bind(user_id)
    .bind(&csrf_hash)
    .bind(lifetime)
    .execute(pool)
    .await;

    let mut response = Redirect::temporary(redirect_to).into_response();

    let session_cookie = format!(
        "{}={}; Path=/; SameSite=Lax; Max-Age={}; HttpOnly{}",
        state.auth.cookie_name,
        session_token,
        lifetime,
        if state.auth.secure_cookie {
            "; Secure"
        } else {
            ""
        }
    );
    let csrf_cookie = format!(
        "{}={}; Path=/; SameSite=Lax; HttpOnly{}",
        state.auth.csrf_cookie_name,
        csrf_token,
        if state.auth.secure_cookie {
            "; Secure"
        } else {
            ""
        }
    );

    if let Ok(v) = axum::http::HeaderValue::from_str(&session_cookie) {
        response
            .headers_mut()
            .append(header::SET_COOKIE, v);
    }
    if let Ok(v) = axum::http::HeaderValue::from_str(&csrf_cookie) {
        response
            .headers_mut()
            .append(header::SET_COOKIE, v);
    }

    response
}
