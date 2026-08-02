<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * Flash messages partial — type-aware flash banner rendering in two modes:
 * ViewContext-driven (from layouts, reads $view->__flash) or direct
 * session reads (raw layouts, pass $keys for $_SESSION['_flash']).
 * Styling per type: error/success/info/flash.
 * ═══════════════════════════════════════════════════════════════════════
 */

/**
 * Render type-aware flash message banners.
 *
 * @param object|null   $viewContext The ViewContext object (from layout pages).
 * @param string[]|null $keys        Session keys to read directly from $_SESSION['_flash'].
 * @param bool          $noContainer Skip the outer container div (card/panel layouts).
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
