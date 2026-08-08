<?php
declare(strict_types=1);

// ─── All JSON API routes live under /api ───────────────────────────
//
// BYO API keys live in browser localStorage only — the server never sees them.

$router->group('/api', function () use ($router) {

    // ─── Health / Me / Context (unauthenticated) ───────────────
    $router->get('/health',       [\Controllers\ApiController::class,       'health']);
    $router->get('/me',           [\Controllers\ApiController::class,       'me']);

    // ─── Paws & Parcels SSO trust anchor (server-to-server) ───────
    // Phase 2 legacy bridge — keeps working for installs that haven't
    // migrated to OIDC. Phase 3 adds /api/oauth/* next to it; both
    // coexist.
    $router->post('/sso/verify-session', [\Controllers\ApiController::class, 'ssoVerifySession']);

    // ─── Phase 3 — OIDC issuer surface ────────────────────────────
    // Discover, JWKS, token, userinfo, and authorize. Trusted clients
    // (pre-registered in oauth_clients — seeded for Paws) hit
    // /authorize, get a code via the local login form, exchange the code
    // at /token, and inspect /userinfo with the access_token. /jwks and
    // /.well-known/openid-configuration are public.
    $router->group('/oauth', function () use ($router) {
        $router->get('/authorize',              [\Controllers\OAuthController::class, 'authorize']);
        $router->post('/authorize',             [\Controllers\OAuthController::class, 'authorize']);
        $router->post('/token',                 [\Controllers\OAuthController::class, 'token']);
        $router->get('/userinfo',               [\Controllers\OAuthController::class, 'userinfo']);
        $router->get('/.well-known/jwks.json',  [\Controllers\OAuthController::class, 'jwks']);
        $router->get('/.well-known/openid-configuration', [\Controllers\OAuthController::class, 'discovery']);
    });

    // ─── Protected routes (authenticated users) ──────────────
    // Chat, context, and the per-user project file manager are open to
    // ALL authenticated roles (Member, Pro, Admin) — everyone gets one
    // project repo to work in.
    $router->group('', ['middleware' => ['auth']], function () use ($router) {
        $router->post('/chat',        [\Controllers\ChatController::class,   'chat']);
        $router->post('/chat/stream', [\Controllers\ChatController::class,   'chatStream']);
        $router->get('/chat/resolve', [\Controllers\ChatController::class,   'resolve']);
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
