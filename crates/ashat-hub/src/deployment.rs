use std::{
    fs, io,
    path::{Path, PathBuf},
};

use axum::{
    extract::{Query, State},
    http::StatusCode,
    response::{IntoResponse, Response},
    routing::post,
    Json, Router,
};
use serde::{Deserialize, Serialize};
use uuid::Uuid;

use crate::{auth, response::error_response, AppState};

#[derive(Debug, Deserialize)]
struct ProjectRequest {
    project_id: String,
}
#[derive(Debug, Deserialize)]
struct ProjectQuery {
    project_id: String,
}
#[derive(Debug, Serialize)]
struct DeploymentResponse {
    ok: bool,
    project_id: String,
    status: String,
    url: Option<String>,
    deployment_id: Option<String>,
    file_count: Option<usize>,
}

pub(crate) fn routes() -> Router<AppState> {
    Router::new()
        .route("/api/galileo/deploy", post(deploy))
        .route("/api/galileo/deploy/redeploy", post(deploy))
        .route("/api/galileo/deploy/status", post(status).get(status_get))
        .route("/api/galileo/deploy/undeploy", post(undeploy))
        .route("/api/galileo/deploy/rollback", post(rollback))
}

async fn deploy(
    State(state): State<AppState>,
    auth::AuthenticatedUser(user): auth::AuthenticatedUser,
    Json(input): Json<ProjectRequest>,
) -> Response {
    publish(&state, &user.id, &input.project_id).await
}

async fn status(
    State(state): State<AppState>,
    auth::AuthenticatedUser(user): auth::AuthenticatedUser,
    Json(input): Json<ProjectRequest>,
) -> Response {
    deployment_status(&state, &user.id, &input.project_id).await
}

async fn status_get(
    State(state): State<AppState>,
    auth::AuthenticatedUser(user): auth::AuthenticatedUser,
    Query(input): Query<ProjectQuery>,
) -> Response {
    deployment_status(&state, &user.id, &input.project_id).await
}

async fn undeploy(
    State(state): State<AppState>,
    auth::AuthenticatedUser(user): auth::AuthenticatedUser,
    Json(input): Json<ProjectRequest>,
) -> Response {
    if !safe_segment(&input.project_id) {
        return error_response(StatusCode::BAD_REQUEST, "invalid_project_id");
    }
    let target = state.deploy_root.join(&user.id).join(&input.project_id);
    if target.exists() {
        if let Err(error) = fs::remove_dir_all(&target) {
            tracing::error!(?error, "deployment removal failed");
            return error_response(StatusCode::SERVICE_UNAVAILABLE, "deployment_unavailable");
        }
    }
    if let Some(pool) = state.db.as_ref() {
        let _ = sqlx::query("UPDATE galileo_deployments SET status='undeployed',deployed_at=? WHERE user_id=? AND project_id=?").bind(now()).bind(&user.id).bind(&input.project_id).execute(pool).await;
    }
    tracing::info!(event = "galileo.deployment.undeployed", user_id = %user.id, project_id = %input.project_id);
    Json(DeploymentResponse {
        ok: true,
        project_id: input.project_id,
        status: "undeployed".to_owned(),
        url: None,
        deployment_id: None,
        file_count: None,
    })
    .into_response()
}

async fn rollback(
    State(state): State<AppState>,
    auth::AuthenticatedUser(user): auth::AuthenticatedUser,
    Json(input): Json<ProjectRequest>,
) -> Response {
    if !safe_segment(&input.project_id) {
        return error_response(StatusCode::BAD_REQUEST, "invalid_project_id");
    }
    let backup_dir = state
        .deploy_backup_root
        .join(&user.id)
        .join(&input.project_id);
    let Ok(entries) = fs::read_dir(&backup_dir) else {
        return error_response(StatusCode::NOT_FOUND, "deployment_backup_not_found");
    };
    let mut backups: Vec<PathBuf> = entries
        .filter_map(Result::ok)
        .map(|entry| entry.path())
        .filter(|path| path.is_dir())
        .collect();
    backups.sort();
    let Some(previous) = backups.pop() else {
        return error_response(StatusCode::NOT_FOUND, "deployment_backup_not_found");
    };
    let target = state.deploy_root.join(&user.id).join(&input.project_id);
    let current_backup = backup_dir.join(format!("rollback-{}", now()));
    if target.is_dir() {
        fs::rename(&target, &current_backup).map_err(|_| ()).ok();
    }
    if let Err(error) = fs::rename(&previous, &target) {
        tracing::error!(?error, "deployment rollback failed");
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "deployment_unavailable");
    }
    let url = deployment_url(&user.id, &input.project_id);
    if let Some(pool) = state.db.as_ref() {
        let _ = sqlx::query("UPDATE galileo_deployments SET status='deployed',url=?,deployed_at=? WHERE user_id=? AND project_id=?").bind(&url).bind(now()).bind(&user.id).bind(&input.project_id).execute(pool).await;
    }
    Json(DeploymentResponse {
        ok: true,
        project_id: input.project_id,
        status: "deployed".to_owned(),
        url: Some(url),
        deployment_id: Some("rollback".to_owned()),
        file_count: None,
    })
    .into_response()
}

async fn publish(state: &AppState, user_id: &str, project_id: &str) -> Response {
    if !safe_segment(project_id) || !safe_segment(user_id) {
        return error_response(StatusCode::BAD_REQUEST, "invalid_project_id");
    }
    let source = state.projects_root.join(user_id).join(project_id);
    let metadata = match fs::symlink_metadata(&source) {
        Ok(metadata) => metadata,
        Err(_) => return error_response(StatusCode::NOT_FOUND, "project_not_found"),
    };
    if !metadata.is_dir() || metadata.file_type().is_symlink() {
        return error_response(StatusCode::FORBIDDEN, "project_storage_invalid");
    }
    let target_parent = state.deploy_root.join(user_id);
    let target = target_parent.join(project_id);
    let stage = target_parent.join(format!(".stage-{}", Uuid::new_v4().simple()));
    let backup_dir = state.deploy_backup_root.join(user_id).join(project_id);
    if let Err(error) = fs::create_dir_all(&stage) {
        tracing::error!(?error, "deployment staging failed");
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "deployment_unavailable");
    }
    let copied = match copy_project(&source, &stage) {
        Ok(count) => count,
        Err(error) => {
            let _ = fs::remove_dir_all(&stage);
            tracing::warn!(?error, "deployment copy failed");
            return error_response(StatusCode::BAD_REQUEST, "deployment_copy_failed");
        }
    };
    if copied == 0 {
        let _ = fs::remove_dir_all(&stage);
        return error_response(StatusCode::BAD_REQUEST, "project_empty");
    }
    if let Err(error) = fs::create_dir_all(&backup_dir) {
        let _ = fs::remove_dir_all(&stage);
        tracing::error!(?error, "deployment backup directory failed");
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "deployment_unavailable");
    }
    if target.exists() {
        let backup = backup_dir.join(format!("{}-{}", now(), Uuid::new_v4().simple()));
        if let Err(error) = fs::rename(&target, backup) {
            let _ = fs::remove_dir_all(&stage);
            tracing::error!(?error, "deployment backup failed");
            return error_response(StatusCode::SERVICE_UNAVAILABLE, "deployment_unavailable");
        }
    }
    if let Err(error) = fs::rename(&stage, &target) {
        let _ = fs::remove_dir_all(&stage);
        tracing::error!(?error, "deployment publish failed");
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "deployment_unavailable");
    }
    let deployment_id = format!("dep_{}", Uuid::new_v4().simple());
    let url = deployment_url(user_id, project_id);
    if let Some(pool) = state.db.as_ref() {
        let result = sqlx::query("INSERT INTO galileo_deployments (user_id,project_id,deployment_id,url,status,file_count,deployed_at) VALUES (?,?,?,?, 'deployed',?,?) ON DUPLICATE KEY UPDATE deployment_id=VALUES(deployment_id),url=VALUES(url),status='deployed',file_count=VALUES(file_count),deployed_at=VALUES(deployed_at)").bind(user_id).bind(project_id).bind(&deployment_id).bind(&url).bind(copied as i64).bind(now()).execute(pool).await;
        if let Err(error) = result {
            tracing::warn!(?error, "deployment record unavailable");
        }
    }
    tracing::info!(
        event = "galileo.deployment.published",
        user_id,
        project_id,
        files = copied
    );
    Json(DeploymentResponse {
        ok: true,
        project_id: project_id.to_owned(),
        status: "deployed".to_owned(),
        url: Some(url),
        deployment_id: Some(deployment_id),
        file_count: Some(copied),
    })
    .into_response()
}

async fn deployment_status(state: &AppState, user_id: &str, project_id: &str) -> Response {
    if !safe_segment(project_id) {
        return error_response(StatusCode::BAD_REQUEST, "invalid_project_id");
    }
    let target = state.deploy_root.join(user_id).join(project_id);
    let deployed = target.is_dir();
    let url = deployed.then(|| deployment_url(user_id, project_id));
    Json(DeploymentResponse {
        ok: true,
        project_id: project_id.to_owned(),
        status: if deployed { "deployed" } else { "undeployed" }.to_owned(),
        url,
        deployment_id: None,
        file_count: deployed.then(|| count_files(&target)),
    })
    .into_response()
}

fn copy_project(source: &Path, target: &Path) -> io::Result<usize> {
    let mut count = 0;
    for entry in fs::read_dir(source)? {
        let entry = entry?;
        let file_type = entry.file_type()?;
        if file_type.is_symlink()
            || entry.file_name() == ".meta.json"
            || entry.file_name() == ".preview.log"
        {
            continue;
        }
        let source_path = entry.path();
        let target_path = target.join(entry.file_name());
        if file_type.is_dir() {
            fs::create_dir_all(&target_path)?;
            count += copy_project(&source_path, &target_path)?;
        } else if file_type.is_file() {
            if let Some(parent) = target_path.parent() {
                fs::create_dir_all(parent)?;
            }
            fs::copy(source_path, target_path)?;
            count += 1;
        }
    }
    Ok(count)
}
fn count_files(root: &Path) -> usize {
    fs::read_dir(root)
        .map(|entries| {
            entries
                .filter_map(Result::ok)
                .map(|entry| {
                    if entry.path().is_dir() {
                        count_files(&entry.path())
                    } else if entry.path().is_file() {
                        1
                    } else {
                        0
                    }
                })
                .sum()
        })
        .unwrap_or_default()
}
fn deployment_url(user_id: &str, project_id: &str) -> String {
    format!("/host/{}/{}", user_id, project_id)
}
fn safe_segment(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 120
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'-' || byte == b'_')
}
fn now() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|duration| duration.as_secs() as i64)
        .unwrap_or_default()
}
