use std::{
    fs,
    path::{Component, Path, PathBuf},
};

use axum::{
    extract::{Path as AxumPath, State},
    http::{header, StatusCode, Uri},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};

use crate::{response::error_response, AppState};

const MAX_ASSET_BYTES: u64 = 10 * 1024 * 1024;

pub(crate) fn routes() -> Router<AppState> {
    Router::new()
        .route("/", get(index))
        .route("/host/*path", get(host))
        .fallback(fallback)
}

async fn index(State(state): State<AppState>) -> Response {
    serve_spa(&state.web_root)
}

async fn host(State(state): State<AppState>, AxumPath(path): AxumPath<String>) -> Response {
    let segments: Vec<&str> = path
        .split('/')
        .filter(|segment| !segment.is_empty())
        .collect();
    if segments.len() < 2
        || segments.iter().any(|segment| {
            segment.starts_with('.')
                || !segment
                    .bytes()
                    .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b'.'))
        })
    {
        return error_response(StatusCode::NOT_FOUND, "not_found");
    }
    let relative = segments.join("/");
    let Some(path) = safe_join(&state.deploy_root, &relative) else {
        return error_response(StatusCode::NOT_FOUND, "not_found");
    };
    let path = if path.is_dir() {
        path.join("index.html")
    } else {
        path
    };
    serve_file(&path).unwrap_or_else(|| error_response(StatusCode::NOT_FOUND, "not_found"))
}

async fn fallback(State(state): State<AppState>, uri: Uri) -> Response {
    let path = uri.path();
    if path.starts_with("/api/") || path == "/api" {
        return error_response(StatusCode::NOT_FOUND, "not_found");
    }
    if path.starts_with("/host/") {
        return error_response(StatusCode::NOT_FOUND, "not_found");
    }
    if path.split('/').any(|segment| segment == "..") {
        return error_response(StatusCode::FORBIDDEN, "forbidden");
    }
    let relative = path.trim_start_matches('/');
    if !relative.is_empty() && !relative.ends_with('/') {
        if let Some(response) = serve_asset(&state.web_root, relative) {
            return response;
        }
        if Path::new(relative).extension().is_some() {
            return error_response(StatusCode::NOT_FOUND, "not_found");
        }
    }
    serve_spa(&state.web_root)
}

fn serve_spa(root: &Path) -> Response {
    serve_asset(root, "index.html").unwrap_or_else(|| {
        error_response(StatusCode::SERVICE_UNAVAILABLE, "web_bundle_unavailable")
    })
}

fn serve_asset(root: &Path, relative: &str) -> Option<Response> {
    let path = safe_join(root, relative)?;
    let metadata = fs::metadata(&path).ok()?;
    if !metadata.is_file() || metadata.len() > MAX_ASSET_BYTES {
        return None;
    }
    serve_file(&path)
}

fn serve_file(path: &Path) -> Option<Response> {
    let metadata = fs::metadata(path).ok()?;
    if !metadata.is_file() || metadata.len() > MAX_ASSET_BYTES {
        return None;
    }
    let content = fs::read(path).ok()?;
    let content_type = content_type(path);
    Some(
        (
            StatusCode::OK,
            [(header::CONTENT_TYPE, content_type)],
            content,
        )
            .into_response(),
    )
}

fn safe_join(root: &Path, relative: &str) -> Option<PathBuf> {
    if relative.is_empty()
        || relative.split('/').any(|segment| {
            segment.is_empty() || segment == ".." || segment.starts_with('.') || segment.len() > 255
        })
    {
        return None;
    }
    let relative_path = Path::new(relative);
    if relative_path.is_absolute()
        || relative_path.components().any(|component| {
            matches!(
                component,
                Component::ParentDir | Component::RootDir | Component::Prefix(_)
            )
        })
    {
        return None;
    }
    let canonical_root = fs::canonicalize(root).ok()?;
    let candidate = canonical_root.join(relative_path);
    let canonical_candidate = fs::canonicalize(candidate).ok()?;
    canonical_candidate
        .starts_with(&canonical_root)
        .then_some(canonical_candidate)
}

fn content_type(path: &Path) -> &'static str {
    match path
        .extension()
        .and_then(|extension| extension.to_str())
        .unwrap_or_default()
    {
        "html" => "text/html; charset=utf-8",
        "css" => "text/css; charset=utf-8",
        "js" => "text/javascript; charset=utf-8",
        "json" => "application/json; charset=utf-8",
        "svg" => "image/svg+xml",
        "png" => "image/png",
        "jpg" | "jpeg" => "image/jpeg",
        "ico" => "image/x-icon",
        "webp" => "image/webp",
        "woff" => "font/woff",
        "woff2" => "font/woff2",
        "map" => "application/json; charset=utf-8",
        _ => "application/octet-stream",
    }
}

#[cfg(test)]
mod tests {
    use super::content_type;
    use std::path::Path;

    #[test]
    fn content_types_cover_vite_assets() {
        assert_eq!(
            content_type(Path::new("index.html")),
            "text/html; charset=utf-8"
        );
        assert_eq!(
            content_type(Path::new("app.js")),
            "text/javascript; charset=utf-8"
        );
        assert_eq!(
            content_type(Path::new("app.css")),
            "text/css; charset=utf-8"
        );
    }
}
