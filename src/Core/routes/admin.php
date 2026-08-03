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

    // Support ticket management
    $router->get('/support',                  [\Controllers\SupportController::class, 'adminIndex']);
    $router->post('/support/status',          [\Controllers\SupportController::class, 'adminUpdateStatus']);
    $router->post('/support/{id}/delete',     [\Controllers\SupportController::class, 'adminDelete']);
});
