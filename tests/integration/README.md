# Integration verification scripts

Authentic, end-to-end checks that drive the **real** extension classes against
**real** encoders and a real on-disk layout. They are standalone PHP scripts
(not PHPUnit) because they require external binaries; run them directly.

## `pipeline.php` — full lifecycle

Exercises the whole flow with the real `ImageProcessor`, `WebPRepo`,
`OptimizerFactory`/`VipsOptimizer`, `HtmlRewriter` and `ZopfliRecompressor`
(only MediaWiki's File/RepoGroup/DB/Logger are stubbed):

1. **Upload** — first-pass optimization + WebP of the original.
2. **Transformation** — thumbnail → WebP (FileTransformed logic).
3. **Serving** — `<img>` → `<picture><source type=image/webp>`.
4. **On-demand maturation** — a missing thumbnail WebP is regenerated at render.
5. **Second pass** — real `zopflipng` recompression of the original PNG.

Then it audits the produced disk tree (valid WebP, no orphan temp files).

### Requirements
- PHP with the **GD** extension (to synthesize the test image).
- **libvips** CLI (`vips`) — the script forces `VaultTecMediaOptimizerImageEngine = vips`.
- **zopflipng** on PATH (for the second-pass step).

### Run
```bash
php tests/integration/pipeline.php
# custom vips path:
VTMO_VIPS=/usr/local/bin/vips php tests/integration/pipeline.php
```

All checks print `✅` on success. A non-zero count of `❌` means a regression.

## `gif.php` — lossless GIF optimization

Drives the real `GifOptimizer` against the real `gifsicle` binary and proves the
optimization is **lossless and animation-safe**:

1. **Availability gating** — enabled+present ⇒ usable; disabled or missing
   binary ⇒ safe no-op (`optimize()` returns `null`, file untouched).
2. **Shrinks** the file (keep-if-smaller), and the reported bytes-saved is exact.
3. **No resize** — logical width/height unchanged.
4. **Animation intact** — frame count and the loop-forever flag preserved.
5. **Lossless render** — every frame is coalesced to full size and hashed as
   canonical raw RGBA; the signature is identical before/after (PNG encoding is
   deliberately bypassed, as it can vary without any pixel change).
6. **Idempotent** — a second pass never grows the file and stays lossless.
7. **No orphan temp files** left behind.

### Requirements
- **gifsicle** on PATH.
- ImageMagick **`convert`** (the script generates its own animated GIF and does
  the render-true comparison; no fixtures needed).

### Run
```bash
php tests/integration/gif.php
```

## `gifAnimatedWebp.php` — animated-GIF / WebP safety (GD backend)

Proves the GD backend never turns an animation into a frozen image. GD can only
decode a GIF's first frame, so emitting a WebP for an animated GIF would replace
the animation with a still once it is served. Drives the real
`GifAnimationDetector`, `GdOptimizer` and `ImageProcessor` (forced GD backend)
over real `gifsicle`/`convert`-generated GIFs:

1. **Detection** — animated vs still GIF told apart (missing file ⇒ not animated).
2. **GD refusal** — `convertToWebP()` on an animated GIF returns `false` with no
   file written; a still GIF still converts to a valid WebP (palette → truecolor).
3. **End-to-end** — through `ImageProcessor`: an animated GIF is recorded
   `complete` with **no WebP on disk** and the animation preserved, while a still
   GIF gets its WebP. (Imagick/libvips produce animated WebP and are unaffected.)

### Requirements
- PHP **GD** extension **with WebP support**.
- **gifsicle** and ImageMagick **`convert`** on PATH.

### Run
```bash
php tests/integration/gifAnimatedWebp.php
```
