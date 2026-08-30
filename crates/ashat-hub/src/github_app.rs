use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{IntoResponse, Redirect, Response},
    routing::get,
    Json, Router,
};
use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine};
use rsa::{pkcs1v15::SigningKey, pkcs8::DecodePrivateKey, RsaPrivateKey};
use rsa::signature::{RandomizedSigner, SignatureEncoding};
use serde::{Deserialize, Serialize};
use sha2::Sha256;
use uuid::Uuid;

use crate::{auth, response::error_response, AppState};

const INSTALL_URL: &str = "https://github.com/apps/ashat-hub/installations/new";

#[derive(Debug, Deserialize)]
struct CallbackQuery {
    installation_id: Option<u64>,
    setup_action: Option<String>,
    state: String,
}

#[derive(Debug, Serialize)]
struct Repository {
    id: u64,
    name: String,
    full_name: String,
    private: bool,
    clone_url: String,
}

#[derive(Debug, Deserialize)]
struct GithubRepository {
    id: u64,
    name: String,
    full_name: String,
    private: bool,
    clone_url: String,
}

pub(crate) fn routes() -> Router<AppState> {
    Router::new()
        .route("/api/github/app/install", get(install))
        .route("/api/github/app/callback", get(callback))
        .route("/api/github/app/repositories", get(repositories))
}

async fn install(State(state): State<AppState>, auth::AuthenticatedUser(_user): auth::AuthenticatedUser) -> Response {
    let state_token = Uuid::new_v4().to_string();
    let mut response = Redirect::temporary(&format!("{INSTALL_URL}?state={state_token}")).into_response();
    let cookie = format!("ashat_github_app_state={state_token}; Path=/; Max-Age=600; HttpOnly; SameSite=Lax{}", if state.auth.secure_cookie { "; Secure" } else { "" });
    if let Ok(value) = HeaderValue::from_str(&cookie) { response.headers_mut().append(header::SET_COOKIE, value); }
    response
}

async fn callback(
    State(state): State<AppState>,
    headers: HeaderMap,
    Query(query): Query<CallbackQuery>,
) -> Response {
    let Some(pool) = state.db.as_ref() else { return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_unavailable"); };
    let Some(user) = auth::authenticated_user(pool, &state, &headers).await.ok().flatten() else { return error_response(StatusCode::UNAUTHORIZED, "unauthenticated"); };
    let expected = headers.get(header::COOKIE).and_then(|value| value.to_str().ok()).and_then(|value| value.split(';').find_map(|part| part.trim().strip_prefix("ashat_github_app_state="))).unwrap_or("");
    if expected.is_empty() || expected != query.state || query.setup_action.as_deref() == Some("uninstall") {
        return error_response(StatusCode::BAD_REQUEST, "github_app_state_invalid");
    }
    let Some(installation_id) = query.installation_id else { return error_response(StatusCode::BAD_REQUEST, "github_installation_missing"); };
    if sqlx::query("UPDATE users SET github_app_installation_id=? WHERE id=?").bind(installation_id).bind(&user.id).execute(pool).await.is_err() {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "github_installation_unavailable");
    }
    let mut response = Redirect::temporary("/").into_response();
    response.headers_mut().append(header::SET_COOKIE, HeaderValue::from_static("ashat_github_app_state=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax"));
    response
}

async fn repositories(State(state): State<AppState>, auth::AuthenticatedUser(user): auth::AuthenticatedUser) -> Response {
    let Some(pool) = state.db.as_ref() else { return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_unavailable"); };
    let (installation_id, oauth_token) = match sqlx::query_as::<_, (Option<u64>, Option<String>)>("SELECT github_app_installation_id,github_access_token FROM users WHERE id=?").bind(&user.id).fetch_one(pool).await {
        Ok(values) => values,
        Err(_) => return error_response(StatusCode::NOT_FOUND, "github_not_connected"),
    };
    let (url, token) = if let Some(installation_id) = installation_id {
        let token = match installation_token(&state, installation_id).await {
            Ok(token) => token,
            Err(error) => { tracing::warn!(?error, "GitHub installation token failed"); return error_response(StatusCode::BAD_GATEWAY, "github_installation_token_failed"); }
        };
        ("https://api.github.com/installation/repositories", token)
    } else if let Some(token) = oauth_token {
        ("https://api.github.com/user/repos?per_page=100&sort=updated", token)
    } else {
        return error_response(StatusCode::NOT_FOUND, "github_not_connected");
    };
    let response = match state.client.get(url).header(header::USER_AGENT, "AshatHub").bearer_auth(token).send().await {
        Ok(response) if response.status().is_success() => response,
        _ => return error_response(StatusCode::BAD_GATEWAY, "github_repositories_failed"),
    };
    let value = match response.json::<serde_json::Value>().await { Ok(value) => value, Err(_) => return error_response(StatusCode::BAD_GATEWAY, "github_repositories_invalid") };
    let repositories = value.get("repositories").cloned().unwrap_or(value);
    let repositories = match serde_json::from_value::<Vec<GithubRepository>>(repositories) { Ok(repositories) => repositories, Err(_) => return error_response(StatusCode::BAD_GATEWAY, "github_repositories_invalid") };
    Json(repositories.into_iter().map(|repo| Repository { id: repo.id, name: repo.name, full_name: repo.full_name, private: repo.private, clone_url: repo.clone_url }).collect::<Vec<_>>()).into_response()
}

async fn installation_token(state: &AppState, installation_id: u64) -> Result<String, Box<dyn std::error::Error + Send + Sync>> {
    let app_id = std::env::var("ASHAT_GITHUB_APP_ID")?;
    let key_path = std::env::var("ASHAT_GITHUB_APP_PRIVATE_KEY")?;
    let key = std::fs::read_to_string(key_path)?;
    let private_key = RsaPrivateKey::from_pkcs8_pem(&key)?;
    let header = URL_SAFE_NO_PAD.encode(br#"{"alg":"RS256","typ":"JWT"}"#);
    let now = chrono::Utc::now().timestamp();
    let payload = URL_SAFE_NO_PAD.encode(serde_json::json!({"iat": now - 60, "exp": now + 540, "iss": app_id}).to_string());
    let message = format!("{header}.{payload}");
    let signing_key = SigningKey::<Sha256>::new(private_key);
    let signature = signing_key.sign_with_rng(&mut rand::thread_rng(), message.as_bytes());
    let jwt = format!("{message}.{}", URL_SAFE_NO_PAD.encode(signature.to_bytes()));
    let response = state.client.post(format!("https://api.github.com/app/installations/{installation_id}/access_tokens")).header(header::USER_AGENT, "AshatHub").bearer_auth(jwt).send().await?.error_for_status()?;
    #[derive(Deserialize)] struct Token { token: String }
    Ok(response.json::<Token>().await?.token)
}
