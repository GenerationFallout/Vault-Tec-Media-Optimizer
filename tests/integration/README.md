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
