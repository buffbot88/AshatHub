# Omega HUD Dark — AshatHub UI Overhaul (Design)

Date: 2026-08-11 · Status: approved · Scope: AshatHub only

## Goal

Restyle AshatHub from the vB3 "Aria Citrus" light override to a cohesive
dark HUD theme under one visual language shared with the Omega telemetry
webui (`ashatnueralhost/frontend`). No existing assets modified.

## Decisions

| Decision | Choice |
|---|---|
| Scope | AshatHub only (telemetry webui untouched) |
| Direction | Omega HUD dark |
| Aria Citrus | Replaced outright; file left dormant, unreferenced |
| Delivery | New `omega-hud.css` override + one link swap in `header.php` |
| Typography | Newsreader serif for headings, JetBrains Mono eyebrows, Inter UI |
| HUD intensity | Mild HUD with tasteful accent glow on hover/links, vignette, grid, lamps |
| Olympus layer | Greek-divine accent: gold secondary, marble wash, carved corner ticks, inscribed serif glow (restrained, dark kept) |

## Files

- **New** `AshatHub/public/css/omega-hud.css` — override layer, loaded last
- **Edit** `AshatHub/src/views/layouts/header.php` — swap `site-theme.css`
  link for `omega-hud.css` (one line)
- **Untouched** `app.css`, `site-theme.css` (dormant), JS, views,
  tailwind config
- **Edit** `router.php` — fixed dev-server static-file serving (see below)

## Palette (from telemetry `scene.ts` COLORS)

```
bg #05070a · soft #0a0d12 · panels #0b0f15 · lines #1c2431/#141a24
text #e8edf4 · text-soft #a3aebd · mute #6b7684 · dim #3d4754
accent #ff7a45 · cyan #39c2d6 (secondary) · ok #3ddc97 · warn #f2b23e · err #ff5d5d
```

## Component map

- `:root` vars re-declared: surfaces, text, accent, status, gold aliases
- Header strip: flat dark, orange active states (drop gradient animation)
- `glass-card` → flat dark panels, hairline borders, no shadow
- `btn-gold` → solid `#ff7a45`, dark ink text, hover brightness; remove
  gradient + shine sweep (`vbShine::after`)
- `chip-gold`, `.field`, links → Omega tokens
- Chat page: dark panels, light editor shell + Monaco (browser-side theme
  unchanged)
- Status: ok/warn/err lamps, subtle `vbPulse` reuse, no glow
- Page bg: faint 56px grid (telemetry parity)
- Section titles: mono eyebrow + Newsreader heading
- `.dark` rules become real dark (`html.dark` finally does something)
- Mermaid (architecture-review page) → Omega vars

## Dev-server fix (root cause of "unstyled / trash" render)

`router.php` set the built-in server's docroot to the module dir, so every
asset under `public/` (CSS/JS/images) was served empty → the page rendered
as bare HTML. Fixed by reading static files from their absolute
`public/` path with the correct MIME type instead of `return false`.

Run dev with: `php -S localhost:8000 router.php` (no `-t` needed).

## Olympus layer (added post-approval)

Dark HUD kept; Olympus = restrained Greek-divine accent only:
- `--gold #e7c178` secondary (orange `#ff7a45` stays primary)
- fixed marble-stone radial wash (`body::before`, `z-index:-1`)
- gold eyebrows/chips, inscribed serif text-glow on `.section-title` + hero `em`
- carved 1px gold corner ticks on `.glass-card`
- gold hairline under header, gold rim on `.btn-gold` hover
- all gated by `prefers-reduced-motion`

## Doctrine

Mild HUD decoration (grid, lamps) is a deliberate exception to Plainspoken
flat rule; static, respects `prefers-reduced-motion`. Marked `ponytail:`
in code.

## Out of scope

- JS changes, theme switcher, site-theme.css deletion (keep for revert),
  telemetry webui, Monaco theme