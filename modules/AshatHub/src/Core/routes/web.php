<?php
declare(strict_types=1);

// ─── Home ──────────────────────────────────────────────────────────
$router->get('/',                [\Controllers\HomeController::class,     'index']);

// ─── Community ─────────────────────────────────────────────────────
$router->get('/community',       [\Controllers\CommunityController::class, 'index']);
$router->post('/community/submit',[\Controllers\CommunityController::class, 'submit']);
$router->get('/community/user/{username}', [\Controllers\CommunityController::class, 'publisher']);
$router->get('/community/project/{slug}', [\Controllers\CommunityController::class, 'show']);
$router->get('/community/project/{slug}/edit', [\Controllers\CommunityController::class, 'edit']);
$router->post('/community/project/{slug}/edit', [\Controllers\CommunityController::class, 'update']);
$router->post('/community/project/{slug}/delete', [\Controllers\CommunityController::class, 'delete']);

// ─── Support Tickets (authenticated users) ──────────────────────────
$router->group('/support', ['middleware' => ['auth']], function () use ($router) {
    $router->get('',              [\Controllers\SupportController::class, 'index']);
    $router->get('/create',       [\Controllers\SupportController::class, 'createForm']);
    $router->post('',             [\Controllers\SupportController::class, 'store']);
    $router->get('/{id}',         [\Controllers\SupportController::class, 'show']);
    $router->post('/{id}/reply',  [\Controllers\SupportController::class, 'reply']);
});

// ─── Docs ──────────────────────────────────────────────────────────
$router->get('/docs',            [\Controllers\DocsController::class,     'index']);
$router->get('/docs/{slug}',     [\Controllers\DocsController::class,     'show']);

// ─── Legal ──────────────────────────────────────────────────────────
$router->get('/terms',           [\Controllers\HomeController::class,   'terms']);
$router->get('/privacy',         [\Controllers\HomeController::class,   'privacy']);

// ─── Themed error pages ────────────────────────────────────────────
$router->get('/error/{code}',    [\Controllers\ErrorController::class,   'show']);
// ─── Hosting (authenticated users) ────────────────────────────────
$router->group('/hosting', ['middleware' => ['auth']], function () use ($router) {
    $router->get('',     [\Controllers\HostingController::class, 'index']);
    $router->post('/submit', [\Controllers\HostingController::class, 'submit']);
    // User self-service: pause / resume / delete (ownership-checked)
    $router->post('/{id}/pause',   [\Controllers\HostingController::class, 'pause']);
    $router->post('/{id}/resume',  [\Controllers\HostingController::class, 'resume']);
    $router->post('/{id}/delete',  [\Controllers\HostingController::class, 'delete']);
});
