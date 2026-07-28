<?php
/**
 * ✦ Ashat Gold Pulse — Reusable gold decorations partial.
 *
 * Includes:
 *   - Floating gold particle canvas (10 rising dots with varied speeds)
 *   - Dashed spinning gear decorations (top-left + bottom-right)
 *
 * Place this inside a `position: relative` container to scope the gears;
 * the particles are always fixed (full viewport). This partial is safe
 * to include on any page — duplicate particles are harmless (CSS
 * dedupes via nth-child overrides).
 */
?>
<!-- ✦ Floating gold particles -->
<div class="particles" aria-hidden="true">
  <span></span><span></span><span></span><span></span><span></span>
  <span></span><span></span><span></span><span></span><span></span>
</div>

<!-- ⚙️ Dashed spinning gears — anchors to the nearest position:relative ancestor (usually <main>) -->
<div class="gear-deco gear-deco-tl" aria-hidden="true"></div>
<div class="gear-deco gear-deco-br" aria-hidden="true"></div>
