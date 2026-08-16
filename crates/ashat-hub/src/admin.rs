use axum::{
    extract::{Query, State},
    http::StatusCode,
    response::{IntoResponse, Response},
    routing::{get, post},
    Json, Router,
};
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

use crate::{auth, response::error_response, AppState};

#[derive(Debug, Deserialize)]
struct UserQuery {
    q: Option<String>,
    page: Option<u32>,
}

#[derive(Debug, Serialize, FromRow)]
struct AdminUserRow {
    id: String,
    username: String,
    email: String,
    display_name: String,
    role: String,
    is_active: i8,
    email_verified_at: Option<String>,
}

#[derive(Debug, Deserialize)]
struct UserRoleRequest {
    user_id: String,
    role: String,
}

#[derive(Debug, Deserialize)]
struct UserStatusRequest {
    user_id: String,
    is_active: bool,
}

pub(crate) fn routes() -> Router<AppState> {
    Router::new()
        .route("/api/admin/summary", get(summary))
        .route("/api/admin/users", get(users))
        .route("/api/admin/users/role", post(update_role))
        .route("/api/admin/users/status", post(update_status))
        .route("/api/admin/database/status", get(database_status))
        .route("/api/admin/settings", get(settings))
        .route("/api/admin/support", get(support))
}

async fn summary(
    State(state): State<AppState>,
    auth::AdminUser(_admin): auth::AdminUser,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_unavailable");
    };
    let users = sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM users")
        .fetch_one(pool)
        .await
        .unwrap_or(0);
    let active_users = sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM users WHERE is_active=1")
        .fetch_one(pool)
        .await
        .unwrap_or(0);
    let open_tickets = sqlx::query_scalar::<_, i64>(
        "SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')",
    )
    .fetch_one(pool)
    .await
    .unwrap_or(0);
    Json(serde_json::json!({
        "users": users,
        "active_users": active_users,
        "open_tickets": open_tickets,
        "gateway_metrics": state.metrics.snapshot(),
        "database_manager": "retired",
    }))
    .into_response()
}

async fn users(
    State(state): State<AppState>,
    auth::AdminUser(_admin): auth::AdminUser,
    Query(query): Query<UserQuery>,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_unavailable");
    };
    let page = query.page.unwrap_or(1).clamp(1, 1000);
    let offset = (page - 1) * 50;
    let search = query.q.unwrap_or_default();
    let result = sqlx::query_as::<_, AdminUserRow>(
        "SELECT id,username,email,display_name,role,is_active,email_verified_at
         FROM users
         WHERE (?='' OR username LIKE CONCAT('%',?,'%') OR email LIKE CONCAT('%',?,'%') OR display_name LIKE CONCAT('%',?,'%'))
         ORDER BY username LIMIT 50 OFFSET ?",
    )
    .bind(&search)
    .bind(&search)
    .bind(&search)
    .bind(&search)
    .bind(offset)
    .fetch_all(pool)
    .await;
    match result {
        Ok(rows) => {
            Json(serde_json::json!({"users":rows,"page":page,"page_size":50})).into_response()
        }
        Err(error) => {
            tracing::error!(?error, "admin user listing failed");
            error_response(StatusCode::SERVICE_UNAVAILABLE, "users_unavailable")
        }
    }
}

async fn update_role(
    State(state): State<AppState>,
    auth::AdminUser(admin): auth::AdminUser,
    Json(input): Json<UserRoleRequest>,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_unavailable");
    };
    if !safe_id(&input.user_id) || !["Member", "Pro", "Admin"].contains(&input.role.as_str()) {
        return error_response(StatusCode::BAD_REQUEST, "invalid_user_role");
    }
    if input.user_id == admin.id && input.role != "Admin" {
        return error_response(StatusCode::CONFLICT, "cannot_demote_self");
    }
    match sqlx::query("UPDATE users SET role=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
        .bind(&input.role)
        .bind(&input.user_id)
        .execute(pool)
        .await
    {
        Ok(result) if result.rows_affected() == 1 => {
            tracing::warn!(event="admin.user.role_changed", admin_id=%admin.id, user_id=%input.user_id, role=%input.role);
            Json(serde_json::json!({"ok":true,"user_id":input.user_id,"role":input.role}))
                .into_response()
        }
        Ok(_) => error_response(StatusCode::NOT_FOUND, "user_not_found"),
        Err(error) => {
            tracing::error!(?error, "admin role update failed");
            error_response(StatusCode::SERVICE_UNAVAILABLE, "users_unavailable")
        }
    }
}

async fn update_status(
    State(state): State<AppState>,
    auth::AdminUser(admin): auth::AdminUser,
    Json(input): Json<UserStatusRequest>,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_unavailable");
    };
    if !safe_id(&input.user_id) {
        return error_response(StatusCode::BAD_REQUEST, "invalid_user");
    }
    if input.user_id == admin.id && !input.is_active {
        return error_response(StatusCode::CONFLICT, "cannot_disable_self");
    }
    match sqlx::query("UPDATE users SET is_active=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
        .bind(input.is_active)
        .bind(&input.user_id)
        .execute(pool)
        .await
    {
        Ok(result) if result.rows_affected() == 1 => {
            if !input.is_active {
                let _ = sqlx::query("DELETE FROM rust_sessions WHERE user_id=?")
                    .bind(&input.user_id)
                    .execute(pool)
                    .await;
            }
            tracing::warn!(event="admin.user.status_changed", admin_id=%admin.id, user_id=%input.user_id, is_active=input.is_active);
            Json(serde_json::json!({"ok":true,"user_id":input.user_id,"is_active":input.is_active}))
                .into_response()
        }
        Ok(_) => error_response(StatusCode::NOT_FOUND, "user_not_found"),
        Err(error) => {
            tracing::error!(?error, "admin status update failed");
            error_response(StatusCode::SERVICE_UNAVAILABLE, "users_unavailable")
        }
    }
}

async fn database_status(
    State(state): State<AppState>,
    auth::AdminUser(_admin): auth::AdminUser,
) -> Response {
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_unavailable");
    };
    let version = sqlx::query_scalar::<_, String>("SELECT VERSION()")
        .fetch_one(pool)
        .await
        .unwrap_or_else(|_| "unknown".to_owned());
    let migrations = sqlx::query_as::<_, (i64, String, bool)>(
        "SELECT version,description,success FROM _sqlx_migrations ORDER BY version",
    )
    .fetch_all(pool)
    .await
    .unwrap_or_default();
    Json(serde_json::json!({
        "version": version,
        "migrations": migrations,
        "maintenance": "migration-driven",
        "arbitrary_sql": "retired",
    }))
    .into_response()
}

async fn settings(
    State(state): State<AppState>,
    auth::AdminUser(_admin): auth::AdminUser,
) -> Response {
    Json(serde_json::json!({
        "runtime": "rust",
        "frontend": "vite",
        "email_verification_enabled": state.auth.email_verification_enabled,
        "secure_cookie": state.auth.secure_cookie,
        "trusted_proxy_headers": state.auth.trust_proxy_headers,
        "arbitrary_database_manager": "retired",
    }))
    .into_response()
}

async fn support(
    State(state): State<AppState>,
    auth::AdminUser(_admin): auth::AdminUser,
) -> Response {
    // The member support handler already applies admin visibility rules.
    // Keep this endpoint explicit for the Rust/Vite admin navigation contract.
    let Some(pool) = state.db.as_ref() else {
        return error_response(StatusCode::SERVICE_UNAVAILABLE, "database_unavailable");
    };
    match sqlx::query("SELECT id,subject,status,priority,category,created_at,updated_at FROM support_tickets ORDER BY updated_at DESC LIMIT 100").fetch_all(pool).await {
        Ok(rows) => {
            let tickets = rows.into_iter().map(|row| {
                use sqlx::Row;
                serde_json::json!({
                    "id": row.try_get::<String, _>("id").unwrap_or_default(),
                    "subject": row.try_get::<String, _>("subject").unwrap_or_default(),
                    "status": row.try_get::<String, _>("status").unwrap_or_default(),
                    "priority": row.try_get::<String, _>("priority").unwrap_or_default(),
                    "category": row.try_get::<String, _>("category").unwrap_or_default(),
                    "created_at": row.try_get::<String, _>("created_at").unwrap_or_default(),
                    "updated_at": row.try_get::<String, _>("updated_at").unwrap_or_default(),
                })
            }).collect::<Vec<_>>();
            Json(serde_json::json!({"tickets":tickets})).into_response()
        }
        Err(error) => { tracing::error!(?error, "admin support listing failed"); error_response(StatusCode::SERVICE_UNAVAILABLE, "support_unavailable") }
    }
}

fn safe_id(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 191
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_'))
}
