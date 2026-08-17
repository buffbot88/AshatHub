use axum::{
    extract::{Path, Query, State},
    http::{header, StatusCode},
    response::{IntoResponse, Response},
    routing::{get, post},
    Json, Router,
};
use rand::{distributions::Alphanumeric, Rng};
use serde::{Deserialize, Serialize};

use uuid::Uuid;

use crate::{response::error_response, AppState};

// ── Request / Response types ──────────────────────────────────────

#[derive(Debug, Serialize)]
struct DeviceAuthResponse {
    device_code: String,
    user_code: String,
    verification_url: String,
    expires_in: u64,
}

#[derive(Debug, Serialize)]
struct DevicePollResponse {
    status: String,
    session_token: Option<String>,
    csrf_token: Option<String>,
}

#[derive(Debug, Deserialize)]
pub(crate) struct LoginQuery {
    pub(crate) code: String,
}

#[derive(Debug, Deserialize)]
struct ValidateRequest {
    session_token: String,
}

#[derive(Debug, Serialize)]
struct ValidateResponse {
    valid: bool,
    user_id: Option<String>,
}

// ── Routes ────────────────────────────────────────────────────────

pub(crate) fn routes() -> Router<AppState> {
    Router::new()
        .route("/api/icarus/auth/device", post(start_device_auth))
        .route(
            "/api/icarus/auth/device/{code}",
            get(poll_device_auth),
        )
        .route("/api/icarus/auth/login", get(login_page))
        .route(
            "/api/icarus/auth/google/callback",
            get(google_callback),
        )
        .route("/api/icarus/auth/validate", post(validate_session))
}

// ── Helpers ───────────────────────────────────────────────────────

/// Generate a short user code like `ABCD-1234`.
fn generate_user_code() -> String {
    let mut rng = rand::thread_rng();
    let letters: String = (0..4)
        .map(|_| rng.sample(Alphanumeric) as char)
        .map(|c| c.to_ascii_uppercase())
        .collect();
    let digits: String = (0..4)
        .map(|_| rng.sample(Alphanumeric) as char)
        .map(|c| c.to_ascii_uppercase())
        .collect();
    format!("{}-{}", letters, digits)
}

fn html_response(html: String) -> Response {
    (
        StatusCode::OK,
        [(header::CONTENT_TYPE, "text/html; charset=utf-8")],
        html,
    )
        .into_response()
}

// ── POST /api/icarus/auth/device ──────────────────────────────────

async fn start_device_auth(State(state): State<AppState>) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_not_configured");
    };

    let device_code = Uuid::new_v4().to_string().replace('-', "");
    let user_code = generate_user_code();
    let expires_at = chrono::Utc::now() + chrono::Duration::minutes(10);

    if let Err(error) = sqlx::query(
        "INSERT INTO icarus_devices (device_code, user_code, status, created_at, expires_at)
         VALUES (?, ?, 'pending', UTC_TIMESTAMP(), ?)",
    )
    .bind(&device_code)
    .bind(&user_code)
    .bind(expires_at.naive_utc())
    .execute(pool)
    .await
    {
        tracing::error!(?error, "Failed to create Icarus device auth");
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "device_auth_failed");
    }

    let base_url = state
        .hub_public_url
        .as_deref()
        .unwrap_or("http://localhost:3101");
    let verification_url = format!(
        "{}/api/icarus/auth/login?code={}",
        base_url, user_code
    );

    Json(DeviceAuthResponse {
        device_code,
        user_code,
        verification_url,
        expires_in: 600,
    })
    .into_response()
}

// ── GET /api/icarus/auth/device/{code} ────────────────────────────

async fn poll_device_auth(
    State(state): State<AppState>,
    Path(code): Path<String>,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_not_configured");
    };

    let row = sqlx::query_as::<_, (String, Option<String>, Option<String>)>(
        "SELECT status, session_token, csrf_token
         FROM icarus_devices
         WHERE device_code = ? AND expires_at > UTC_TIMESTAMP()",
    )
    .bind(&code)
    .fetch_optional(pool)
    .await;

    match row {
        Ok(Some((status, session_token, csrf_token))) => match status.as_str() {
            "authorized" => Json(DevicePollResponse {
                status: "authorized".to_owned(),
                session_token,
                csrf_token,
            })
            .into_response(),
            "expired" | "denied" => Json(DevicePollResponse {
                status,
                session_token: None,
                csrf_token: None,
            })
            .into_response(),
            _ => Json(DevicePollResponse {
                status: "pending".to_owned(),
                session_token: None,
                csrf_token: None,
            })
            .into_response(),
        },
        Ok(None) => error_response(StatusCode::NOT_FOUND, "device_code_not_found"),
        Err(error) => {
            tracing::error!(?error, "Icarus device poll failed");
            error_response(StatusCode::SERVICE_UNAVAILABLE, "database_error")
        }
    }
}

// ── GET /api/icarus/auth/login?code={user_code} ───────────────────

async fn login_page(
    State(state): State<AppState>,
    Query(params): Query<LoginQuery>,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return html_response(error_page("Service unavailable"));
    };

    // Validate the user_code exists and is still pending.
    let row = sqlx::query_as::<_, (String, String)>(
        "SELECT device_code, status
         FROM icarus_devices
         WHERE user_code = ? AND expires_at > UTC_TIMESTAMP()",
    )
    .bind(&params.code)
    .fetch_optional(pool)
    .await;

    let (device_code, status) = match row {
        Ok(Some(row)) => row,
        Ok(None) => return html_response(error_page("Invalid or expired code")),
        Err(_) => return html_response(error_page("Service unavailable")),
    };

    if status != "pending" {
        return html_response(error_page("This code has already been used"));
    }

    let Some(oauth) = state.google_oauth.as_ref() else {
        return html_response(error_page("Google sign-in is not configured"));
    };
    if !oauth.is_configured() {
        return html_response(error_page("Google sign-in is not configured"));
    }

    // Build the Google OAuth URL carrying the device_code in the state param.
    let state_token = format!("icarus:{}", device_code);
    let hub_url = state
        .hub_public_url
        .as_deref()
        .unwrap_or("http://localhost:3101");
    let redirect_uri = format!("{}/api/icarus/auth/google/callback", hub_url);

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
        urlencoding::encode(&redirect_uri),
        urlencoding::encode(&state_token),
    );

    html_response(login_html(&params.code, &auth_url))
}

// ── GET /api/icarus/auth/google/callback ───────────────────────────

async fn google_callback(
    State(state): State<AppState>,
    Query(params): Query<crate::google_auth::GoogleCallbackParams>,
) -> Response {
    let Some(oauth) = &state.google_oauth else {
        return html_response(error_page("Google auth not configured"));
    };

    if let Some(error) = &params.error {
        return html_response(error_page(&format!(
            "Google sign-in failed: {}",
            error
        )));
    }

    let Some(code) = &params.code else {
        return html_response(error_page("Missing authorization code"));
    };

    let Some(state_param) = &params.state else {
        return html_response(error_page("Missing state parameter"));
    };

    // Only accept Icarus state tokens.
    let Some(device_code) = state_param.strip_prefix("icarus:") else {
        return html_response(error_page("Invalid state parameter"));
    };

    // Exchange the authorization code for tokens.
    let token_response = match crate::google_auth::exchange_code(oauth, code).await {
        Ok(response) => response,
        Err(error) => {
            tracing::error!(?error, "Icarus: Google token exchange failed");
            return html_response(error_page("Failed to complete Google sign-in"));
        }
    };

    // Fetch the user's Google profile.
    let google_user =
        match crate::google_auth::fetch_google_user(&token_response.access_token).await {
            Ok(user) => user,
            Err(error) => {
                tracing::error!(?error, "Icarus: Google userinfo fetch failed");
                return html_response(error_page("Failed to get user information"));
            }
        };

    let Some(pool) = state.db.as_ref() else {
        return html_response(error_page("Database unavailable"));
    };

    // Find or create the local user, then ensure the Google account is linked.
    let user_id =
        if let Some(id) = crate::google_auth::find_user_by_email(pool, &google_user.email).await {
            id
        } else {
            crate::google_auth::create_user_from_google(pool, &google_user).await
        };
    crate::google_auth::link_google_to_user(pool, &user_id, &google_user).await;

    // Create a Rust session the CLI can reuse for authenticated API calls.
    let session_token = crate::auth::new_token();
    let csrf_token = crate::auth::new_token();
    let session_hash = crate::auth::hash_token(&session_token);
    let csrf_hash = crate::auth::hash_token(&csrf_token);
    let lifetime = state.auth.session_lifetime_seconds;

    if let Err(error) = sqlx::query(
        "INSERT INTO rust_sessions
            (session_hash, user_id, csrf_hash, ip, user_agent, created_at, last_seen, expires_at)
         VALUES (?, ?, ?, 'icarus-cli', 'icarus-cli',
                 UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                 DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))",
    )
    .bind(&session_hash)
    .bind(&user_id)
    .bind(&csrf_hash)
    .bind(lifetime)
    .execute(pool)
    .await
    {
        tracing::error!(?error, "Icarus: session creation failed");
        return html_response(error_page("Failed to create session"));
    }

    // Mark the device code as authorized so the CLI poll returns the tokens.
    let _ = sqlx::query(
        "UPDATE icarus_devices
         SET status = 'authorized', session_token = ?, csrf_token = ?, user_id = ?
         WHERE device_code = ?",
    )
    .bind(&session_token)
    .bind(&csrf_token)
    .bind(&user_id)
    .bind(device_code)
    .execute(pool)
    .await;

    html_response(success_page())
}

// ── POST /api/icarus/auth/validate ────────────────────────────────

async fn validate_session(
    State(state): State<AppState>,
    Json(input): Json<ValidateRequest>,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_not_configured");
    };

    let session_hash = crate::auth::hash_token(&input.session_token);

    let row = sqlx::query_as::<_, (String,)>(
        "SELECT u.id
         FROM rust_sessions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE s.session_hash = ? AND s.expires_at > UTC_TIMESTAMP() AND u.is_active = 1
         LIMIT 1",
    )
    .bind(&session_hash)
    .fetch_optional(pool)
    .await;

    match row {
        Ok(Some((user_id,))) => Json(ValidateResponse {
            valid: true,
            user_id: Some(user_id),
        })
        .into_response(),
        Ok(None) => Json(ValidateResponse {
            valid: false,
            user_id: None,
        })
        .into_response(),
        Err(error) => {
            tracing::error!(?error, "Icarus: session validation failed");
            error_response(StatusCode::SERVICE_UNAVAILABLE, "validation_failed")
        }
    }
}

// ── HTML pages ────────────────────────────────────────────────────

fn login_html(code: &str, auth_url: &str) -> String {
    format!(
        r##"<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Icarus — Sign in to AGP Studios</title>
  <style>
    :root {{ --bg: #0a0a0a; --surface: #121212; --text: #f3f0e8; --muted: #beb7aa;
             --accent: #c9a24a; --line: rgba(201,162,74,.22); }}
    * {{ box-sizing: border-box; margin: 0; padding: 0; }}
    body {{ min-height: 100vh; display: grid; place-items: center; background: var(--bg);
            color: var(--text); font-family: 'Inter', system-ui, sans-serif; padding: 24px; }}
    .card {{ width: min(100%, 420px); padding: 32px; background: var(--surface);
             border: 1px solid var(--line); border-radius: 6px; text-align: center; }}
    .eyebrow {{ display: block; margin-bottom: 10px; color: var(--accent);
                font: 600 11px 'JetBrains Mono', monospace; letter-spacing: .12em;
                text-transform: uppercase; }}
    h1 {{ font-size: 32px; margin-bottom: 8px; }}
    .code {{ display: inline-block; margin: 16px 0; padding: 10px 20px;
             background: var(--bg); border: 1px solid var(--line); border-radius: 4px;
             font: 600 24px 'JetBrains Mono', monospace; letter-spacing: .08em;
             color: var(--accent); }}
    p {{ color: var(--muted); font-size: 14px; line-height: 1.6; margin-bottom: 20px; }}
    .google-btn {{ display: inline-flex; align-items: center; gap: 10px; padding: 12px 24px;
                   color: var(--text); background: var(--surface); border: 1px solid var(--line);
                   border-radius: 4px; font-size: 15px; font-weight: 600; text-decoration: none;
                   transition: border-color .15s; }}
    .google-btn:hover {{ border-color: var(--accent); }}
    .google-btn svg {{ flex-shrink: 0; }}
  </style>
</head>
<body>
  <div class="card">
    <span class="eyebrow">Icarus CLI</span>
    <h1>Sign in to AGP Studios</h1>
    <p>Enter this code in your terminal to authenticate Icarus:</p>
    <div class="code">{}</div>
    <p>Then sign in with your Google account to complete authentication.</p>
    <a class="google-btn" href="{}">
      <svg viewBox="0 0 24 24" width="20" height="20">
        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/>
        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
      </svg>
      Sign in with Google
    </a>
  </div>
</body>
</html>"##,
        code, auth_url
    )
}

fn success_page() -> String {
    r##"<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Icarus — Authenticated</title>
  <style>
    :root { --bg: #0a0a0a; --surface: #121212; --text: #f3f0e8; --accent: #c9a24a;
            --line: rgba(201,162,74,.22); }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { min-height: 100vh; display: grid; place-items: center; background: var(--bg);
           color: var(--text); font-family: 'Inter', system-ui, sans-serif; padding: 24px; }
    .card { width: min(100%, 420px); padding: 32px; background: var(--surface);
             border: 1px solid var(--line); border-radius: 6px; text-align: center; }
    .eyebrow { display: block; margin-bottom: 10px; color: var(--accent);
               font: 600 11px 'JetBrains Mono', monospace; letter-spacing: .12em;
               text-transform: uppercase; }
    h1 { font-size: 28px; margin-bottom: 12px; }
    .check { font-size: 48px; margin-bottom: 16px; color: var(--accent); }
    p { color: #beb7aa; font-size: 14px; line-height: 1.6; }
  </style>
</head>
<body>
  <div class="card">
    <span class="eyebrow">Icarus CLI</span>
    <div class="check">&#x2713;</div>
    <h1>Authenticated</h1>
    <p>You can now return to your terminal. Icarus is ready to use.</p>
  </div>
</body>
</html>"##
    .to_owned()
}

fn error_page(message: &str) -> String {
    format!(
        r##"<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Icarus — Error</title>
  <style>
    :root {{ --bg: #0a0a0a; --surface: #121212; --text: #f3f0e8; --accent: #c9a24a;
             --line: rgba(201,162,74,.22); }}
    * {{ box-sizing: border-box; margin: 0; padding: 0; }}
    body {{ min-height: 100vh; display: grid; place-items: center; background: var(--bg);
            color: var(--text); font-family: 'Inter', system-ui, sans-serif; padding: 24px; }}
    .card {{ width: min(100%, 420px); padding: 32px; background: var(--surface);
             border: 1px solid var(--line); border-radius: 6px; text-align: center; }}
    .eyebrow {{ display: block; margin-bottom: 10px; color: var(--accent);
                font: 600 11px 'JetBrains Mono', monospace; letter-spacing: .12em;
                text-transform: uppercase; }}
    h1 {{ font-size: 28px; margin-bottom: 12px; color: #f87171; }}
    p {{ color: #beb7aa; font-size: 14px; line-height: 1.6; }}
  </style>
</head>
<body>
  <div class="card">
    <span class="eyebrow">Icarus CLI</span>
    <h1>Error</h1>
    <p>{}</p>
  </div>
</body>
</html>"##,
        message
    )
}
