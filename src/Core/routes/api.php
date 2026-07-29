<?php
declare(strict_types=1);

// ─── All JSON API routes live under /api ───────────────────────────
//
// Note: routes that involve the Coding Agent (/agent/config, /account/api)
// were intentionally removed during the local-first pivot. BYO API keys
// and generated file content now live in browser localStorage only.
// The server never sees them — only metadata (paths, sizes) flows through
// the /api/builds/ endpoint.

$router->group('/api', function () use ($router) {

    // ─── Health / Me / Context (unauthenticated) ───────────────
    $router->get('/health',       [\Controllers\ApiController::class,       'health']);
    $router->get('/me',           [\Controllers\ApiController::class,       'me']);

    // ─── Protected routes (authenticated users) ──────────────
    // Chat and context endpoints are open to all authenticated roles (Member, Pro, Admin)
    $router->group('', ['middleware' => ['auth']], function () use ($router) {
        $router->post('/chat',        [\Controllers\ChatController::class,   'chat']);
        $router->post('/chat/stream', [\Controllers\ChatController::class,   'chatStream']);
        $router->get('/context',      [\Controllers\ApiController::class,    'context']);
    });

    // ─── Protected routes (pro or admin required) ──────────────
    $router->group('', ['middleware' => ['pro-or-admin']], function () use ($router) {

        // ─── Specs ────────────────────────────────────────────
        $router->group('/specs', function () use ($router) {
            $router->get('',          [\Controllers\SpecsController::class,  'list']);
            $router->get('/{id}',     [\Controllers\SpecsController::class,  'show']);
            $router->post('',         [\Controllers\SpecsController::class,  'create']);
            $router->put('/{id}',     [\Controllers\SpecsController::class,  'update']);
            $router->delete('/{id}',  [\Controllers\SpecsController::class,  'delete']);
        });

        // ─── Files ────────────────────────────────────────────
        $router->group('/files', function () use ($router) {
            $router->get('',          [\Controllers\FilesController::class,  'list']);
            $router->get('/{id}',     [\Controllers\FilesController::class,  'show']);
            $router->post('',         [\Controllers\FilesController::class,  'save']);
            $router->delete('/{id}',  [\Controllers\FilesController::class,  'delete']);
        });

        // ─── Builds ───────────────────────────────────────────
        $router->group('/builds', function () use ($router) {
            $router->get('',          [\Controllers\BuildsController::class, 'list']);
            $router->post('',         [\Controllers\BuildsController::class, 'create']);
            $router->post('/{id}/approve', [\Controllers\BuildsController::class, 'approve']);
        });

        // ─── Static asset proxy ─────────────────────────────────
        // Serves files from the public/ directory through the API.
        // Usage: GET /api/asset?path=js/studio/chat.js
        // Useful when mod_rewrite / .htaccess is unavailable.
        $router->get('/asset', [\Controllers\ApiController::class, 'serveAsset']);

        // ─── BrainStem admin config (admin-gate on top of pro-or-admin) ─
        $router->group('/admin', ['middleware' => ['admin-gate']], function () use ($router) {
            $router->get('/brainstem-config',  [\Controllers\ChatController::class, 'getBrainstemConfig']);
            $router->put('/brainstem-config',  [\Controllers\ChatController::class, 'updateBrainstemConfig']);
        });
    });
});
