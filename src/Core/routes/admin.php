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

    // System settings
    $router->get('/settings',       [\Controllers\AdminController::class, 'settings']);
    $router->post('/settings/brainstem',      [\Controllers\AdminController::class, 'updateBrainstem']);
    $router->post('/settings/brainstem/reset', [\Controllers\AdminController::class, 'resetBrainstem']);

    // Maintenance mode toggle
    $router->post('/settings/maintenance', [\Controllers\AdminController::class, 'toggleMaintenance']);

    // GitHub update — incremental API mode (no exec/git required, works on any host)
    $router->get('/settings/github-check',  [\Controllers\AdminController::class, 'checkGitHubUpdates']);
    $router->post('/settings/github-apply', [\Controllers\AdminController::class, 'applyGitHubUpdates']);

    // Webhook secret management
    $router->get('/settings/webhook-secret',  [\Controllers\AdminController::class, 'webhookSecret']);
    $router->post('/settings/webhook-secret', [\Controllers\AdminController::class, 'saveWebhookSecret']);

    // Support ticket management
    $router->get('/support',                  [\Controllers\SupportController::class, 'adminIndex']);
    $router->post('/support/status',          [\Controllers\SupportController::class, 'adminUpdateStatus']);
    $router->post('/support/{id}/delete',     [\Controllers\SupportController::class, 'adminDelete']);
});
