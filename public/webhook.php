<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — GitHub Webhook Receiver
 *
 * Standalone entry point (not routed through Core\Router) so the
 * automatic CSRF check doesn't interfere with GitHub's POST delivery.
 *
 * GitHub calls this URL on every push to your repository. The endpoint:
 *   1. Reads the shared secret from storage/webhook-secret.json
 *   2. Verifies the X-Hub-Signature-256 HMAC against the request body
 *   3. Ignores ping events (just returns 200)
 *   4. On push events, runs GitUpdater::incremental() to download only
 *      the files that changed in the latest commits
 *
 * Setup:
 *   1. Set a webhook secret in Admin → Settings → Webhook
 *   2. In your GitHub repo: Settings → Webhooks → Add webhook
 *      - Payload URL: https://yoursite.com/webhook.php
 *      - Content type: application/json
 *      - Secret: (the same secret you set in the admin panel)
 *      - Events: Just the push event
 * ═══════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

// ─── Lightweight bootstrap (LITE_MODE — no session, no DB, no ConfigBag)
// ASHAT_LITE_BOOT tells bootstrap.php to skip Session, ConfigBag,
// and the themed error handler — saving ~75% overhead. The webhook
// only needs the autoloader + constants to run GitUpdater.
define('ASHAT_LITE_BOOT', true);
require __DIR__ . '/../config/bootstrap.php';

// ─── Only accept POST ─────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ─── Read the shared secret ──────────────────────────────────────
$secretFile = ASHAT_ROOT . '/storage/webhook-secret.json';
$secret = '';

if (is_file($secretFile)) {
    $secretData = json_decode(file_get_contents($secretFile), true);
    if (is_array($secretData) && !empty($secretData['secret'])) {
        $secret = (string) $secretData['secret'];
    }
}

if ($secret === '') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

// ─── Verify HMAC signature ────────────────────────────────────────
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if ($signatureHeader === '') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Missing signature']);
    exit;
}

$rawBody = file_get_contents('php://input');
$expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

if (!hash_equals($expectedSignature, $signatureHeader)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
    exit;
}

// ─── Parse event type ─────────────────────────────────────────────
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

// Ping event — GitHub sends this when the webhook is first configured
if ($event === 'ping') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'event' => 'ping', 'message' => 'Webhook is alive']);
    exit;
}

// Only respond to push events
if ($event !== 'push') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'event' => $event, 'message' => 'Ignored (not a push event)']);
    exit;
}

// ─── Run the incremental update ──────────────────────────────────
try {
    $updater = new \Core\GitUpdater();
    $result  = $updater->incremental();

    http_response_code($result['ok'] ? 200 : 500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => $result['ok'],
        'event'   => 'push',
        'summary' => $result['summary'] ?? '',
        'updated' => $result['files_updated'] ?? 0,
        'created' => $result['files_created'] ?? 0,
        'deleted' => $result['files_deleted'] ?? 0,
    ]);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'    => false,
        'event' => 'push',
        'error' => $e->getMessage(),
    ]);
    exit;
}
