//! Minimal OIDC issuer for Paws & Parcels and other AGP Studio web clients.
//!
//! Implements the authorization-code + PKCE flow with RS256 id_tokens:
//!   - GET  /api/oauth/.well-known/openid-configuration   discovery
//!   - GET  /api/oauth/authorize                          login + code issuance
//!   - POST /api/oauth/token                              exchange code for id_token
//!   - GET  /api/oauth/userinfo                           verify Bearer id_token
//!   - GET  /api/oauth/.well-known/jwks.json              RSA public key set
//!
//! The signing key is persisted (default `storage/oidc-signing-key.pem`) so
//! restarting the hub does not invalidate outstanding JWKS caches or id_tokens.

use std::{
    collections::HashMap,
    env,
    path::Path,
    sync::Mutex,
    time::{SystemTime, UNIX_EPOCH},
};

use axum::{
    extract::{Query, State},
    http::{header, StatusCode},
    response::{IntoResponse, Redirect, Response},
    routing::{get, post},
    Json, Router,
};
use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine as _};
use rand::RngCore;
use rsa::{
    pkcs1v15::{Signature, SigningKey, VerifyingKey},
    pkcs8::{DecodePrivateKey, EncodePrivateKey, LineEnding},
    signature::{SignatureEncoding, Signer, Verifier},
    traits::PublicKeyParts,
    RsaPrivateKey, RsaPublicKey,
};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};

use crate::{auth, response::error_response, AppState};

const CODE_TTL_SECS: i64 = 600;
const ID_TOKEN_TTL_SECS: i64 = 3600;
const MAX_CODE_LEN: usize = 191;

/// A pending authorization code (single-use, PKCE-bound, short-lived).
#[derive(Clone, Debug)]
pub(crate) struct OidcCode {
    pub client_id: String,
    pub redirect_uri: String,
    pub code_challenge: Option<String>,
    pub user_id: String,
    pub username: String,
    pub email: String,
    pub role: String,
    pub expires_at: i64,
    pub used: bool,
}

/// The OIDC signing key plus the in-memory code store.
pub(crate) struct OidcIssuer {
    pub(crate) kid: String,
    private_key: RsaPrivateKey,
    pub(crate) codes: Mutex<HashMap<String, OidcCode>>,
}

impl OidcIssuer {
    pub fn load_or_create() -> Self {
        let key = load_or_create_key();
        let kid = auth::new_token();
        Self {
            kid,
            private_key: key,
            codes: Mutex::new(HashMap::new()),
        }
    }

    pub fn public_key(&self) -> RsaPublicKey {
        RsaPublicKey::from(&self.private_key)
    }

    /// Sign an id_token (RS256) from the given claims.
    pub fn sign_id_token(&self, claims: &serde_json::Value) -> String {
        let header = serde_json::json!({
            "alg": "RS256",
            "typ": "JWT",
            "kid": self.kid,
        });
        let b64_header = URL_SAFE_NO_PAD.encode(serde_json::to_string(&header).unwrap());
        let b64_payload = URL_SAFE_NO_PAD.encode(serde_json::to_string(claims).unwrap());
        let input = format!("{b64_header}.{b64_payload}");
        let signing_key = SigningKey::<Sha256>::new(self.private_key.clone());
        let signature = signing_key.sign(input.as_bytes());
        let b64_sig = URL_SAFE_NO_PAD.encode(signature.to_vec());
        format!("{input}.{b64_sig}")
    }

    /// Verify an RS256 id_token and return its claims, or None.
    pub fn verify_id_token(&self, token: &str) -> Option<serde_json::Value> {
        let mut parts = token.splitn(3, '.');
        let (b64_header, b64_payload, b64_sig) =
            (parts.next()?, parts.next()?, parts.next()?);
        let input = format!("{b64_header}.{b64_payload}");
        let sig_bytes = URL_SAFE_NO_PAD.decode(b64_sig).ok()?;
        let signature = Signature::try_from(&sig_bytes[..]).ok()?;
        let verifying_key = VerifyingKey::<Sha256>::new(self.public_key());
        verifying_key.verify(input.as_bytes(), &signature).ok()?;
        let payload = URL_SAFE_NO_PAD.decode(b64_payload).ok()?;
        serde_json::from_slice(&payload).ok()
    }

    pub fn jwks(&self) -> serde_json::Value {
        let public = self.public_key();
        let n = URL_SAFE_NO_PAD.encode(public.n().to_bytes_be());
        let e = URL_SAFE_NO_PAD.encode(public.e().to_bytes_be());
        serde_json::json!({
            "keys": [{
                "kty": "RSA",
                "use": "sig",
                "alg": "RS256",
                "kid": self.kid,
                "n": n,
                "e": e,
            }]
        })
    }

    /// Issue a new single-use authorization code bound to the PKCE challenge.
    fn issue_code(&self, code: OidcCode) -> String {
        let token = auth::new_token();
        let mut guard = self.codes.lock().unwrap();
        // Sweep expired entries to keep the map bounded.
        let now = unix_now();
        guard.retain(|_, entry| entry.expires_at > now);
        guard.insert(token.clone(), code);
        token
    }

    fn take_code(&self, code: &str) -> Option<OidcCode> {
        let mut guard = self.codes.lock().unwrap();
        let now = unix_now();
        guard.remove(code).filter(|entry| entry.expires_at > now)
    }
}

// ── Routes ─────────────────────────────────────────────────────────

pub(crate) fn routes() -> Router<AppState> {
    Router::new()
        .route(
            "/api/oauth/.well-known/openid-configuration",
            get(discovery),
        )
        .route("/api/oauth/authorize", get(authorize))
        .route("/api/oauth/token", post(token))
        .route("/api/oauth/userinfo", get(userinfo))
        .route("/api/oauth/.well-known/jwks.json", get(jwks))
}

// ── Request types ──────────────────────────────────────────────────

#[derive(Debug, Deserialize)]
struct AuthorizeQuery {
    response_type: Option<String>,
    client_id: Option<String>,
    redirect_uri: Option<String>,
    scope: Option<String>,
    state: Option<String>,
    code_challenge: Option<String>,
    code_challenge_method: Option<String>,
}

#[derive(Debug, Deserialize)]
struct TokenRequest {
    grant_type: Option<String>,
    code: Option<String>,
    redirect_uri: Option<String>,
    client_id: Option<String>,
    code_verifier: Option<String>,
}

#[derive(Debug, Serialize)]
struct TokenResponse {
    id_token: String,
    token_type: String,
    expires_in: i64,
}

#[derive(Debug, Deserialize)]
struct UserInfoQuery {
    access_token: Option<String>,
}

// ── Endpoints ──────────────────────────────────────────────────────

fn issuer_url(state: &AppState) -> String {
    state
        .hub_public_url
        .as_deref()
        .filter(|value| !value.trim().is_empty())
        .map(|value| value.trim_end_matches('/').to_owned())
        .unwrap_or_else(|| "https://www.agpstudios.org".to_owned())
        + "/api/oauth"
}

async fn discovery(State(state): State<AppState>) -> Response {
    let issuer = issuer_url(&state);
    Json(serde_json::json!({
        "issuer": issuer,
        "authorization_endpoint": format!("{issuer}/authorize"),
        "token_endpoint": format!("{issuer}/token"),
        "userinfo_endpoint": format!("{issuer}/userinfo"),
        "jwks_uri": format!("{issuer}/.well-known/jwks.json"),
        "response_types_supported": ["code"],
        "subject_types_supported": ["public"],
        "id_token_signing_alg_values_supported": ["RS256"],
        "scopes_supported": ["openid", "profile"],
        "code_challenge_methods_supported": ["S256"],
    }))
    .into_response()
}

async fn jwks(State(state): State<AppState>) -> Response {
    Json(state.oidc.jwks()).into_response()
}

/// GET /api/oauth/authorize — the browser lands here from Paws' login page.
/// If the user already has a hub session, issue the code immediately.
/// Otherwise render a login page whose Google button returns here with a
/// session (via `/api/auth/google?next=...`), at which point we issue the code.
async fn authorize(
    State(state): State<AppState>,
    Query(params): Query<AuthorizeQuery>,
    headers: axum::http::HeaderMap,
) -> Response {
    let Some(response_type) = params.response_type.as_deref() else {
        return authorize_error("missing response_type");
    };
    if response_type != "code" {
        return authorize_error("unsupported response_type");
    }
    let Some(client_id) = params.client_id.as_deref() else {
        return authorize_error("missing client_id");
    };
    if client_id.is_empty() || client_id.len() > 64 {
        return authorize_error("invalid client_id");
    }
    let Some(redirect_uri) = params.redirect_uri.as_deref() else {
        return authorize_error("missing redirect_uri");
    };
    if !redirect_uri_allowed(redirect_uri) {
        return authorize_error("redirect_uri not allowed");
    }
    let Some(state_param) = params.state.as_deref() else {
        return authorize_error("missing state");
    };
    if state_param.is_empty() || state_param.len() > 512 {
        return authorize_error("invalid state");
    }
    let code_challenge = params.code_challenge.as_deref().filter(|c| !c.is_empty());
    let method = params
        .code_challenge_method
        .as_deref()
        .unwrap_or("S256");
    if method != "S256" {
        return authorize_error("unsupported code_challenge_method");
    }
    if let Some(challenge) = code_challenge {
        if challenge.len() < 43 || challenge.len() > 128 {
            return authorize_error("invalid code_challenge");
        }
    }

    // If the user is already signed in, issue the code and redirect to Paws.
    let Some(pool) = state.db.as_ref() else {
        return authorize_error("service_unavailable");
    };
    let user = auth::authenticated_user(pool, &state, &headers).await.ok().flatten();
    if let Some(user) = user {
        let code = state.oidc.issue_code(OidcCode {
            client_id: client_id.to_owned(),
            redirect_uri: redirect_uri.to_owned(),
            code_challenge: code_challenge.map(str::to_owned),
            user_id: user.id.clone(),
            username: user.username.clone(),
            email: user.email.clone(),
            role: user.role.clone(),
            expires_at: unix_now() + CODE_TTL_SECS,
            used: false,
        });
        let separator = if redirect_uri.contains('?') { '&' } else { '?' };
        return Redirect::temporary(&format!(
            "{redirect_uri}{separator}code={}&state={}",
            urlencoding::encode(&code),
            urlencoding::encode(state_param)
        ))
        .into_response();
    }

    // Not signed in — show a branded login page with a Google button that
    // returns to this exact authorize URL once a session exists.
    let authorize_url = format!(
        "/api/oauth/authorize?response_type=code&client_id={}&redirect_uri={}&scope={}&state={}&code_challenge={}&code_challenge_method=S256",
        urlencoding::encode(client_id),
        urlencoding::encode(redirect_uri),
        urlencoding::encode(params.scope.as_deref().unwrap_or("openid profile")),
        urlencoding::encode(state_param),
        urlencoding::encode(code_challenge.unwrap_or("")),
    );
    let google_url = format!(
        "/api/auth/google?next={}",
        urlencoding::encode(&authorize_url)
    );
    (StatusCode::OK, Html(authorize_login_html(&google_url))).into_response()
}

/// POST /api/oauth/token — Paws' server exchanges the code for an id_token.
async fn token(
    State(state): State<AppState>,
    Json(input): Json<TokenRequest>,
) -> Response {
    if input.grant_type.as_deref() != Some("authorization_code") {
        return token_error("unsupported_grant_type");
    }
    let Some(code) = input.code.as_deref() else {
        return token_error("invalid_grant");
    };
    if code.is_empty() || code.len() > MAX_CODE_LEN {
        return token_error("invalid_grant");
    }
    let Some(entry) = state.oidc.take_code(code) else {
        return token_error("invalid_grant");
    };
    if entry.used {
        return token_error("invalid_grant");
    }
    if let Some(expected_uri) = input.redirect_uri.as_deref() {
        if expected_uri != entry.redirect_uri {
            return token_error("invalid_grant");
        }
    }
    if let Some(expected_client) = input.client_id.as_deref() {
        if expected_client != entry.client_id {
            return token_error("invalid_grant");
        }
    }
    // PKCE: verify the S256 verifier against the stored challenge.
    if let Some(challenge) = &entry.code_challenge {
        let Some(verifier) = input.code_verifier.as_deref() else {
            return token_error("invalid_grant");
        };
        let digest = Sha256::digest(verifier.as_bytes());
        let expected = URL_SAFE_NO_PAD.encode(digest);
        if expected != *challenge {
            return token_error("invalid_grant");
        }
    }

    let now = unix_now();
    let claims = serde_json::json!({
        "iss": issuer_url(&state),
        "sub": entry.user_id,
        "aud": entry.client_id,
        "exp": now + ID_TOKEN_TTL_SECS,
        "iat": now,
        "email": entry.email,
        "username": entry.username,
        "role": entry.role,
    });
    let id_token = state.oidc.sign_id_token(&claims);
    Json(TokenResponse {
        id_token,
        token_type: "Bearer".to_owned(),
        expires_in: ID_TOKEN_TTL_SECS,
    })
    .into_response()
}

/// GET /api/oauth/userinfo — verify the Bearer id_token and return claims.
async fn userinfo(
    State(state): State<AppState>,
    Query(query): Query<UserInfoQuery>,
    headers: axum::http::HeaderMap,
) -> Response {
    let bearer = headers
        .get(header::AUTHORIZATION)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.strip_prefix("Bearer "))
        .or(query.access_token.as_deref());
    let Some(token) = bearer else {
        return error_response(StatusCode::UNAUTHORIZED, "missing_token");
    };
    let Some(claims) = state.oidc.verify_id_token(token) else {
        return error_response(StatusCode::UNAUTHORIZED, "invalid_token");
    };
    Json(claims).into_response()
}

// ── Helpers ────────────────────────────────────────────────────────

fn unix_now() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

/// Allow https redirects and loopback http (for local dev). This mirrors the
/// Paws server config which registers `https://pawsandparcels.agpstudios.org`.
fn redirect_uri_allowed(uri: &str) -> bool {
    if uri.len() > 512 || !uri.contains("://") {
        return false;
    }
    let scheme = uri.splitn(2, "://").next().unwrap_or("");
    match scheme {
        "https" => true,
        "http" => {
            let rest = uri.splitn(2, "://").nth(1).unwrap_or("");
            let host = rest.split(['/', '?', '#']).next().unwrap_or("");
            let host = host.rsplit('@').next().unwrap_or(host);
            let host = host.split(':').next().unwrap_or(host);
            host == "localhost" || host == "127.0.0.1" || host == "[::1]" || host == "::1"
        }
        _ => false,
    }
}

fn authorize_error(message: &str) -> Response {
    (StatusCode::BAD_REQUEST, Html(error_html(message))).into_response()
}

fn token_error(code: &'static str) -> Response {
    error_response(StatusCode::BAD_REQUEST, code)
}

struct Html(String);
impl IntoResponse for Html {
    fn into_response(self) -> Response {
        (
            StatusCode::OK,
            [(header::CONTENT_TYPE, "text/html; charset=utf-8")],
            self.0,
        )
            .into_response()
    }
}

fn authorize_login_html(google_url: &str) -> String {
    format!(
        r##"<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in to AGP Studios</title>
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
    h1 {{ font-size: 30px; margin-bottom: 8px; }}
    p {{ color: var(--muted); font-size: 14px; line-height: 1.6; margin: 12px 0 22px; }}
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
    <span class="eyebrow">AGP Studios</span>
    <h1>Authorize application</h1>
    <p>An AGP Studios application is requesting access to your account. Sign in to continue.</p>
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
        google_url
    )
}

fn error_html(message: &str) -> String {
    format!(
        r##"<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Authorization error</title>
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
    <span class="eyebrow">AGP Studios</span>
    <h1>Authorization error</h1>
    <p>{}</p>
  </div>
</body>
</html>"##,
        message
    )
}

// ── Key management ─────────────────────────────────────────────────

fn load_or_create_key() -> RsaPrivateKey {
    let path = env::var("ASHAT_OIDC_KEY_FILE")
        .unwrap_or_else(|_| "storage/oidc-signing-key.pem".to_owned());
    if let Ok(pem) = std::fs::read_to_string(&path) {
        if let Ok(key) = RsaPrivateKey::from_pkcs8_pem(&pem) {
            return key;
        }
    }
    let mut rng = rand::thread_rng();
    let key = RsaPrivateKey::new(&mut rng, 2048).expect("failed to generate OIDC signing key");
    if let Ok(pem) = key.to_pkcs8_pem(LineEnding::LF) {
        if let Some(parent) = Path::new(&path).parent() {
            let _ = std::fs::create_dir_all(parent);
        }
        let _ = std::fs::write(&path, pem.as_str());
    }
    key
}

// Ensure RngCore is referenced so the trait import isn't flagged as unused.
#[allow(dead_code)]
fn _touch_rng() {
    let mut rng = rand::thread_rng();
    let _ = rng.next_u32();
}
