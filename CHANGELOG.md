# Changelog — Vault-Tec Media Optimizer

All notable changes are documented here. Format based on [Keep a Changelog](https://keepachangelog.com).
The bundled PDF guide documents the **1.4.1** baseline; entries below are relative to it.

---

## [1.8.2]

### 🇫🇷 Résumé
Corrige une **latence de rendu** : le hook `onFileTransformed` exécutait, **en synchrone pendant le rendu**
d'une page et **sans aucun budget**, le second passage **zopflipng** sur chaque miniature PNG nouvellement
générée (et l'AVIF sur la branche expérimentale). Or zopflipng coûte ~14 s/fichier : une galerie en cache
froid pouvait ajouter des minutes au rendu — payées par un visiteur. Ce second passage est désormais **confié à un job en arrière-plan**
(`VaultTecMediaOptimizerThumbnailRecompress`) au lieu de tourner dans le rendu : l'enfilement est quasi
instantané, et la recompression s'exécute **hors requête** quand la file est traitée en ligne de commande
(idéalement `$wgJobRunRate = 0` + `runJobs.php`), si bien qu'**aucun visiteur ne la paie**. En mode gros wiki
(`UseJobQueue = false`) elle est sautée. Le WebP, rapide, reste synchrone (c'est l'asset servi).

### Fixed
- **Synchronous thumbnail second-pass latency in `onFileTransformed`** — the zopflipng recompression of newly
  generated PNG thumbnails (and, on the experimental branch, AVIF generation) ran inline during page render with
  no budget. With zopflipng at ~14 s/file, a cold gallery of N PNGs could add N×14 s to a visitor's request.
  This slow pass is now handed to a **background job** (`VaultTecMediaOptimizerThumbnailRecompress`) instead of
  running during render: enqueuing is near-instant, and the recompression runs **out-of-band** when the queue is
  processed on the command line (ideally `$wgJobRunRate = 0` + `runJobs.php`), so **no visitor pays for it**. It
  operates on the **stored** thumbnail (the temp pre-storage file is gone by then) and is skipped entirely in
  large-wiki mode (`UseJobQueue = false`). The fast WebP encode stays synchronous, as it is the asset the rewriter
  serves. On the experimental branch the job also generates the AVIF with the keep-if-smaller-than-WebP guard.
  **Why the CLI is better:** running the queue out-of-band (or this CLI backfill) is the only way heavy image work
  never lands on a visitor's request — see the manual (§8) and the performance guide.

## [1.8.1]

### 🇫🇷 Résumé
Correctifs de robustesse autour des originaux et des GIF, sans aucun changement de configuration.
Après une optimisation **en place** de l'original (1ʳᵉ passe PNG/JPEG/`gifsicle`), les métadonnées
MediaWiki (`img_sha1`, `img_size`, dimensions) sont désormais **resynchronisées** — la détection de
doublons et l'invalidation du cache des miniatures restaient auparavant pointées sur les octets
d'avant optimisation. Côté **GIF animés sous GD** : GD ne sait décoder que la 1ʳᵉ frame, ce qui
produisait un WebP **figé** servi à la place de l'animation ; le WebP est maintenant **refusé** et le
GIF animé d'origine est conservé. Et un **GIF statique** se convertit enfin correctement en WebP sous
GD (les images à palette étaient rejetées par `imagewebp()`).

### Fixed
- **Stale `img_sha1` / `img_size` after the first pass** — `ImageProcessor` now refreshes MediaWiki's
  stored file metadata (`LocalFile::purgeCache()` + `upgradeRow()`) whenever the in-place first pass
  actually shrinks an original (PNG/JPEG/`gifsicle`). Previously the `image` row kept the
  pre-optimization sha1/size, which breaks duplicate detection and thumbnail cache invalidation. Runs
  only in the deferred job / CLI backfill, never inside the upload transaction; a refresh failure never
  fails an otherwise-successful optimization. Mirrors `ZopfliOriginalProcessor`.
- **Animated GIF turned into a still WebP/AVIF under the GD backend** — GD can only decode a GIF's first
  frame. Since the served format is decided purely by the WebP/AVIF file's presence on disk, a GD-encoded
  copy replaced the animation with a frozen image. A new `GifAnimationDetector` now lets `GdOptimizer`
  refuse both the WebP **and the experimental AVIF** conversion (no file written, original animated GIF
  kept) and lets `ImageProcessor` record such files as **complete with no WebP** instead of failing. Both
  the upload pipeline and on-demand thumbnail generation are covered. Imagick/libvips produce genuine
  animated WebP/AVIF and are unaffected.
- **Still GIF → WebP/AVIF failed under GD** — `imagewebp()`/`imageavif()` reject palette images, so
  single-frame GIFs never got a WebP (nor AVIF) under GD. `GdOptimizer` now promotes GIF input to
  truecolor (preserving transparency as alpha) before encoding, in both paths.

### Tests
- **`tests/integration/gifAnimatedWebp.php`** — drives the real `GifAnimationDetector`, `GdOptimizer`
  (WebP **and** AVIF) and `ImageProcessor` (GD backend) over real `gifsicle`/`convert`-generated GIFs:
  animated GIFs are kept intact with no WebP/AVIF, still GIFs convert to a valid WebP/AVIF.

## [1.8.0]

### 🇫🇷 Résumé
Ajoute l'**optimisation GIF sans perte** (binaire externe `gifsicle`, `-O3`) en **première passe** :
exécutée à l'upload **et** par le rattrapage CLI — pas de second script dédié. Strictement sans perte et
sûre pour l'animation (jamais de redimensionnement, jamais d'altération des frames/cadence/boucle ;
le rendu est identique au pixel près). Le script de rattrapage gagne une option **`--format`** pour ne
traiter que certains formats (ex. `--format=gif`). Désactivé par défaut, opt-in.

### Added
- **Lossless GIF optimization** — `$wgVaultTecMediaOptimizerGifsicleEnabled` (default `false`),
  `$wgVaultTecMediaOptimizerGifsicleBinary` (default `gifsicle`), `$wgVaultTecMediaOptimizerGifsicleLevel`
  (default `3`). First-pass, in-place, **strictly lossless and animation-safe**: only ever passes
  `-O{level}` (structural LZW/inter-frame optimization). Never resizes, never alters frame
  pixels/timing/loop counter; the rendered animation is byte-identical (verified by coalesced-frame
  comparison). Runs on upload and during `maintenance/optimizeImages.php` — **no separate second-pass
  job**. Keep-if-smaller anti-bloat guard + atomic replace. WebP generation always proceeds regardless;
  a missing/disabled `gifsicle` is a harmless no-op. Surfaced on `Special:VTMOStatus`.
- **`--format` filter for `maintenance/optimizeImages.php`** — restrict a catch-up run to a subset of the
  configured formats (e.g. `--format=gif`, `--format=png,jpeg`). Accepts short names or full MIME types;
  intersected with `$wgVaultTecMediaOptimizerFormats` so a disabled type is never processed.

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
