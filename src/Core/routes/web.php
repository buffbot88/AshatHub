<?php
declare(strict_types=1);

// ─── Home ──────────────────────────────────────────────────────────
$router->get('/',                [\Controllers\HomeController::class,     'index']);

// ─── Community ─────────────────────────────────────────────────────
$router->get('/community',       [\Controllers\CommunityController::class, 'index']);
$router->post('/community/submit',[\Controllers\CommunityController::class, 'submit']);
$router->get('/community/project/{slug}', [\Controllers\CommunityController::class, 'show']);

// ─── Docs ──────────────────────────────────────────────────────────
$router->get('/docs',            [\Controllers\DocsController::class,     'index']);
$router->get('/docs/{slug}',     [\Controllers\DocsController::class,     'show']);

// ─── Legal ──────────────────────────────────────────────────────────
$router->get('/terms',           [\Controllers\HomeController::class,   'terms']);
$router->get('/privacy',         [\Controllers\HomeController::class,   'privacy']);

// ─── Themed error pages ────────────────────────────────────────────
$router->get('/error/{code}',    [\Controllers\ErrorController::class,   'show']);
