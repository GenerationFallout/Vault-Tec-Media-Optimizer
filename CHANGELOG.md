# Changelog — Vault-Tec Media Optimizer

All notable changes are documented here. Format based on [Keep a Changelog](https://keepachangelog.com).
The bundled PDF guide documents the **1.4.1** baseline; entries below are relative to it.

---

## [1.7.0]

### 🇫🇷 Résumé
Ajoute un **moteur d'image libvips** optionnel (vitesse/mémoire à grande échelle), un **mode gros wiki**
(traitement en ligne de commande sans saturer la file de jobs) avec les scripts `optimizeImages.php`
et `purgeQueue.php`, l'**AVIF expérimental** (branche dédiée), et **durcit fortement** la robustesse :
écritures atomiques, portabilité des statistiques (SQLite/PostgreSQL), résilience CLI. Aucun changement
de comportement par défaut : tout le nouveau est opt-in.

### Added
- **Optional libvips image engine** — `$wgVaultTecMediaOptimizerImageEngine = 'auto' | 'vips'`
  (+ `$wgVaultTecMediaOptimizerVipsBinary`). When `vips`, first-pass PNG/JPEG optimization and WebP
  encoding run through the external `vips` CLI (same WebP quality, much faster and lighter on memory at
  scale). Automatic, graceful fallback to Imagick/GD if the binary is unusable. `Special:VTMOStatus`
  reports the active engine and probes the binary.
- **`maintenance/optimizeImages.php`** — inline, CLI-driven backfill of existing images (first pass +
  WebP) **without using the job queue**, ideal for large wikis. Options: `--dry-run`, `--batch`, `--max`,
  `--force`, `--start`, `--purge-queue`. Honours the configured image engine.
- **`maintenance/purgeQueue.php`** — dedicated command to empty the OptimizeImage job queue
  (and, with `--include-zopfli`, the second-pass queue); `--dry-run` reports counts.
- **`$wgVaultTecMediaOptimizerUseJobQueue`** (default `true`) — set `false` to stop auto-enqueuing on
  upload (large-wiki mode): originals are then optimized via the CLI on your own schedule, while
  thumbnails still get WebP on demand at render. Surfaced on `Special:VTMOStatus`.
- **`$wgVaultTecMediaOptimizerOnDemandThumbLimit`** (default `5`) — caps how many missing thumbnail
  WebP files are encoded synchronously during a single page render (cold-cache latency guard).
- **EXPERIMENTAL AVIF support** *(experimental branch)* — `$wgVaultTecMediaOptimizerAvifEnabled`
  (+ `AvifDirectory`, `AvifQuality`). Generates AVIF alongside WebP and offers it **before** WebP in
  `<picture>` so capable browsers pick AVIF and others fall back to WebP, then to the original. Real
  AV1-capability probe (a build can expose `heifsave` for HEIC yet lack AV1) → graceful skip when absent.
- **`tests/integration/pipeline.php`** — authentic end-to-end verification driving the real classes over
  real `vips` + `zopflipng` (upload → transform → serve → on-demand → second pass).

### Fixed
- **Statistics crashed on SQLite/PostgreSQL** — the per-format breakdown used the MySQL-only
  `SUBSTRING_INDEX()`. Now computed in PHP (`pathinfo`), portable across all supported backends.
- **`REPLACE INTO` wiped columns** — `markComplete/markFailed/markSkipped` reset unlisted columns to
  defaults. Switched to upsert (`onDuplicateKeyUpdate`) so failures/skips keep diagnostic sizes; completion
  clears `io_png_zopfli_size` explicitly.
- **Stats over-counted "space saved"** — the no-gain second-pass marker stored a literal `1`, which
  `COALESCE(zopfli, optimized)` then treated as the real size. Now sets it equal to the optimized size.
- **Double `<picture>` wrapping** — the idempotency check used a fixed 500-byte look-back that could miss
  a wrapper whose `<source>` pushed the inner `<img>` past the window. Replaced by a single linear pass
  over the real `<picture>` ranges.
- **CLI run aborted on one bad file** — `optimizeImages.php` now wraps each file in a try/catch (an
  exception escaping `ImageProcessor` no longer ends the whole run; still resumable via `--start`).
- **0-byte/truncated derived files were served** — the serve/regenerate guards checked existence, not
  size; a leftover empty WebP/AVIF broke the image (a `<picture>` source has no fallback). Now treated as
  missing: not emitted, and regenerated atomically.
- **libvips integration (found via real-binary testing)** — the previous code used inline `out[opt=val]`
  options, which an explicit vips save operation does NOT honour (it created a file literally named
  `out[opt=val]`); switched to separate `--flag value` arguments. Metadata stripping uses `--keep none`
  (libvips ≥ 8.15; retries without it on older builds).

### Performance
- **On-demand WebP/AVIF render budget** — bounds worst-case render latency on cold caches (see
  `OnDemandThumbLimit`); existing derived files are always served, only a few missing ones are encoded inline.
- **Cached dashboard counters** — `BackfillScheduler` eligible/pending counts (a LEFT JOIN COUNT re-hit on
  every 10 s auto-refresh) are now WAN-cached (10 s TTL).

### Security / robustness
- **Atomic derived-file writes** — WebP/AVIF now go to a **unique** temp file then `rename()` onto the
  destination, so a concurrent reader never sees a half-written file and a failed regeneration no longer
  deletes an existing good file.
- **Unique temp names everywhere** — optimizers and the zopfli/oxipng recompressors no longer use a fixed
  `.tmp` name, removing a concurrency hazard (two processes on the same file could interleave into one temp
  and rename a corrupted result over the original).

---

## [1.4.1] — documented baseline (bundled PDF guide)

Lossless optimization of originals (PNG/JPEG/GIF) + automatic WebP delivery via `<picture>`; on-creation and
on-demand thumbnail WebP; optional zopflipng/oxipng second PNG pass; backfill via job queue; three special
pages (status, backfill, statistics); maintenance scripts `recompressOriginals.php`,
`repairBloatedOriginals.php`. See the full PDF guide for details.
