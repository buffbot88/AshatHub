<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * Flash messages partial — type-aware flash banner rendering.
 *
 * Supports two modes:
 *
 * 1. ViewContext-driven (called from header.php): pass the $view object
 *    as the first argument. Reads $view->__flash and $view->__flash_type.
 *
 * 2. Direct session reads (called from raw-layout pages like
 *    session_login.php): pass null and use $keys to read and clear
 *    specific keys from $_SESSION['_flash'].
 *
 * Usage in a layout (mode 1 — from $view):
 *   <?php partial_flash($view) ?>
 *
 * Usage on a raw page (mode 2 — explicit keys):
 *   <?php partial_flash(null, ['error', 'success'], true) ?>
 *
 * Styling per type:
 *   error   → red border/background/text
 *   success → green border/background/text
 *   info    → gold accent border/background/text (same as default)
 *   flash   → gold accent border/background/text (legacy)
 *
 * ═══════════════════════════════════════════════════════════════════════
 */

/**
 * Render type-aware flash message banners.
 *
 * @param object|null   $viewContext The ViewContext object (from layout pages).
 *                                   Pass null when using $keys on raw layouts.
 * @param string[]|null $keys        Optional session keys to read directly
 *                                   from $_SESSION['_flash'] (for raw layouts).
 *                                   Ignored when $viewContext is provided.
 * @param bool          $noContainer Set to true to skip the outer container div
 *                                   (for use inside card/panel layouts).
 */
function partial_flash(?object $viewContext = null, ?array $keys = null, bool $noContainer = false): void
{
    $flashMessages = [];

    if ($viewContext !== null) {
        // Mode 1: ViewContext-driven (from layout template)
        if (!empty($viewContext->__flash)) {
            $flashMessages[] = [
                'message' => $viewContext->__flash,
                'type'    => $viewContext->__flash_type ?? 'flash',
            ];
        }
    } elseif ($keys !== null) {
        // Mode 2: Direct session reads for raw layouts
        foreach ($keys as $key) {
            if (isset($_SESSION['_flash'][$key])) {
                $flashMessages[] = [
                    'message' => $_SESSION['_flash'][$key],
                    'type'    => $key,
                ];
                unset($_SESSION['_flash'][$key]);
            }
        }
    }

    if (empty($flashMessages)) return;

    $styles = [
        'error'   => 'border-err/30 bg-err/10 text-err',
        'success' => 'border-ok/30 bg-ok/10 text-ok',
        'info'    => 'border-accent/30 bg-accent/5 text-accent',
        'flash'   => 'border-accent/30 bg-accent/5 text-accent',
    ];

    if ($noContainer):
        // Inline mode (inside a card/panel — no outer wrapper)
        foreach ($flashMessages as $msg): ?>
          <div class="rounded border px-4 py-2 text-sm <?= e($styles[$msg['type']] ?? $styles['flash']) ?> mb-3 last:mb-0 text-center">
            <?= e($msg['message']) ?>
          </div>
        <?php endforeach; ?>
    <?php else: ?>
        <!-- Full-width mode (outside content — has container) -->
        <div class="container mx-auto px-6 pt-4">
          <?php foreach ($flashMessages as $msg): ?>
            <div class="rounded border px-4 py-2 text-sm <?= e($styles[$msg['type']] ?? $styles['flash']) ?> mb-2 last:mb-0">
              <?= e($msg['message']) ?>
            </div>
          <?php endforeach; ?>
        </div>
    <?php endif;
}
