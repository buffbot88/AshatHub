<?php
declare(strict_types=1);

// ─── All JSON API routes live under /api ───────────────────────────
//
// BYO API keys live in browser localStorage only — the server never sees them.

$router->group('/api', function () use ($router) {

    // ─── Health / Me / Context (unauthenticated) ───────────────
    $router->get('/health',       [\Controllers\ApiController::class,       'health']);
    $router->get('/me',           [\Controllers\ApiController::class,       'me']);

    // ─── Protected routes (authenticated users) ──────────────
    // Chat, context, and the per-user project file manager are open to
    // ALL authenticated roles (Member, Pro, Admin) — everyone gets one
    // project repo to work in.
    $router->group('', ['middleware' => ['auth']], function () use ($router) {
        $router->post('/chat',        [\Controllers\ChatController::class,   'chat']);
        $router->post('/chat/stream', [\Controllers\ChatController::class,   'chatStream']);
        $router->get('/context',      [\Controllers\ApiController::class,    'context']);

        // ─── Files (per-user project repo — all authenticated roles) ──
        $router->group('/files', function () use ($router) {
            $router->get('',          [\Controllers\FilesController::class,  'list']);
            // Static-suffix routes MUST be declared before /{id} so
            // 'export' / 'import' / 'rename' / 'duplicate' / 'tree'
            // aren't captured as an id.
            $router->get('/export',   [\Controllers\FilesController::class,  'exportZip']);
            $router->post('/import',  [\Controllers\FilesController::class,  'importZip']);
            $router->post('/rename',    [\Controllers\FilesController::class,  'rename']);
            $router->post('/duplicate', [\Controllers\FilesController::class,  'duplicate']);
            $router->delete('/tree',  [\Controllers\FilesController::class,  'deleteTree']);
            $router->get('/read',     [\Controllers\FilesController::class,  'readByPath']);
            $router->get('/{id}',     [\Controllers\FilesController::class,  'show']);
            $router->post('',         [\Controllers\FilesController::class,  'save']);
            $router->delete('/{id}',  [\Controllers\FilesController::class,  'delete']);
        });

        // ─── Folders (empty-folder markers) ──────────────────────
        $router->group('/folders', function () use ($router) {
            $router->post('', [\Controllers\FilesController::class, 'createFolder']);
        });
    });

});
