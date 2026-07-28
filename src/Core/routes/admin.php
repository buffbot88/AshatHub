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

    // GitHub update
    $router->get('/settings/git-status',  [\Controllers\AdminController::class, 'gitStatus']);
    $router->post('/settings/git-pull',   [\Controllers\AdminController::class, 'updateFromGitHub']);
});
