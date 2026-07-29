<?php
declare(strict_types=1);

// ─── Studio / IDE pages ────────────────────────────────────────────
$router->get('/ide',             [\Controllers\StudioController::class,   'dashboard']);
$router->get('/ide/planner',     [\Controllers\StudioController::class,   'planner']);
$router->get('/ide/autonomy',    [\Controllers\StudioController::class,   'autonomy']);
$router->get('/ide/files',        [\Controllers\StudioController::class,   'files']);

