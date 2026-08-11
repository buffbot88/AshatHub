<?php
declare(strict_types=1);

// ─── Admin Panel — all routes require admin role ────────────────────
$router->group('/admin', ['middleware' => ['admin-gate']], function () use ($router) {

    // Dashboard
    $router->get('',                [\Controllers\AdminController::class, 'dashboard']);

    // User management
    $router->get('/users',          [\Controllers\AdminController::class, 'users']);
    $router->post('/users/role',    [\Controllers\AdminController::class, 'updateUserRole']);
    $router->post('/users/toggle-status', [\Controllers\AdminController::class, 'toggleUserStatus']);

    // Community moderation
    $router->post('/projects/approve', [\Controllers\AdminController::class, 'approveProject']);
    $router->post('/projects/reject',  [\Controllers\AdminController::class, 'rejectProject']);

    // System settings
    $router->get('/settings',       [\Controllers\AdminController::class, 'settings']);
    $router->post('/settings/brainstem',      [\Controllers\AdminController::class, 'updateBrainstem']);
    $router->post('/settings/brainstem/reset', [\Controllers\AdminController::class, 'resetBrainstem']);

    // Maintenance mode toggle
    $router->post('/settings/maintenance', [\Controllers\AdminController::class, 'toggleMaintenance']);

    // Database maintenance
    $router->get('/database',              [\Controllers\AdminController::class, 'database']);
    $router->get('/database/export',       [\Controllers\AdminController::class, 'databaseExport']);
    $router->post('/database/query',       [\Controllers\AdminController::class, 'databaseQuery']);
    $router->post('/database/optimize',    [\Controllers\AdminController::class, 'databaseOptimize']);
    $router->post('/database/repair',      [\Controllers\AdminController::class, 'databaseRepair']);
    $router->post('/database/check',       [\Controllers\AdminController::class, 'databaseCheck']);
    $router->post('/database/import',      [\Controllers\AdminController::class, 'databaseImport']);
    $router->post('/database/purge-sessions', [\Controllers\AdminController::class, 'databasePurgeSessions']);

    // Table management
    $router->post('/database/create-table',   [\Controllers\AdminController::class, 'databaseCreateTable']);
    $router->post('/database/drop-table',     [\Controllers\AdminController::class, 'databaseDropTable']);
    $router->post('/database/rename-table',   [\Controllers\AdminController::class, 'databaseRenameTable']);
    $router->post('/database/truncate-table', [\Controllers\AdminController::class, 'databaseTruncateTable']);

    // Row operations
    $router->post('/database/insert-row',     [\Controllers\AdminController::class, 'databaseInsertRow']);
    $router->post('/database/update-row',     [\Controllers\AdminController::class, 'databaseUpdateRow']);
    $router->post('/database/delete-row',     [\Controllers\AdminController::class, 'databaseDeleteRow']);
    $router->post('/database/delete-rows',    [\Controllers\AdminController::class, 'databaseDeleteRows']);

    // Database-level management
    $router->post('/database/create-db',  [\Controllers\AdminController::class, 'databaseCreateDb']);
    $router->post('/database/rename-db',  [\Controllers\AdminController::class, 'databaseRenameDb']);
    $router->post('/database/drop-db',    [\Controllers\AdminController::class, 'databaseDropDb']);

    // Column management
    $router->post('/database/add-column',     [\Controllers\AdminController::class, 'databaseAddColumn']);
    $router->post('/database/drop-column',    [\Controllers\AdminController::class, 'databaseDropColumn']);
    $router->post('/database/modify-column',  [\Controllers\AdminController::class, 'databaseModifyColumn']);

    // Support ticket management
    $router->get('/support',                  [\Controllers\SupportController::class, 'adminIndex']);
    $router->post('/support/status',          [\Controllers\SupportController::class, 'adminUpdateStatus']);
    $router->post('/support/{id}/delete',     [\Controllers\SupportController::class, 'adminDelete']);

    // Hosting management
    $router->post('/hosting/approve',          [\Controllers\AdminController::class, 'approveHosting']);
    $router->post('/hosting/deny',             [\Controllers\AdminController::class, 'denyHosting']);
    $router->post('/hosting/pause',            [\Controllers\AdminController::class, 'pauseHosting']);
    $router->post('/hosting/resume',           [\Controllers\AdminController::class, 'resumeHosting']);
    $router->post('/hosting/delete',           [\Controllers\AdminController::class, 'deleteHosting']);
});

