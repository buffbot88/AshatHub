<?php if (APP_ENV === 'development'): ?>
  <div class="chip-gold fixed bottom-4 right-4 z-50" title="ASHAT Hub · PHP build">
    dev · <?= e(APP_VERSION_DISPLAY) ?> · PHP <?= PHP_VERSION ?>
  </div>
<?php endif; ?>
