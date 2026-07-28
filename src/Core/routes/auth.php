<?php
declare(strict_types=1);

// ─── Desktop-client session auth ───────────────────────────────────
$router->get('/auth/session',    [\Controllers\AuthController::class,    'sessionAuth']);
$router->post('/auth/session',   [\Controllers\AuthController::class,    'sessionAuth']);

// ─── Login / Register / Logout ─────────────────────────────────────
$router->get('/login',           [\Controllers\AuthController::class,    'loginForm']);
$router->post('/login',          [\Controllers\AuthController::class,    'login']);
$router->get('/register',        [\Controllers\AuthController::class,    'registerForm']);
$router->post('/register',       [\Controllers\AuthController::class,    'register']);
$router->post('/logout',         [\Controllers\AuthController::class,    'logout']);

// ─── Account ───────────────────────────────────────────────────────
$router->get('/account',                 [\Controllers\AccountController::class, 'index']);
$router->get('/account/active-users',    [\Controllers\AccountController::class, 'activeUsers']);
$router->put('/account/profile',         [\Controllers\AccountController::class, 'updateProfile']);
