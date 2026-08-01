# Changelog — Vault-Tec Media Optimizer

All notable changes are documented here. Format based on [Keep a Changelog](https://keepachangelog.com).
The bundled PDF guide documents the **1.4.1** baseline; entries below are relative to it.

---

## [1.9.1]

### 🇫🇷 Résumé
Audit du code **de la 1.9.0 elle-même** (le seul périmètre que les passes précédentes n'avaient jamais relu), et
correction de trois défauts que j'y avais introduits. **(1)** L'invalidation du cache de statistiques avait été
ajoutée sur `markComplete/markFailed/markSkipped`, c'est-à-dire le chemin **par-fichier** du rattrapage — alors
que le code documentait explicitement, quelques lignes plus bas, qu'on ne l'invalide **délibérément pas** sur les
écritures fréquentes. Un purge WAN par fichier maintient le cache en *hold-off* pendant toute la durée d'un
rattrapage : le tableau de bord (qui s'auto-rafraîchit) recalculait alors les agrégats complets à **chaque**
chargement — exactement l'inverse du cache introduit en 1.7.0. Invalidation désormais réservée aux actions
**discrètes** (suppression d'un fichier, bouton « réinitialiser les échecs »). **(2)** La détection de
divergence de taille de la 2ᵉ passe comparait en `!==` strict une valeur qui peut être une chaîne (colonne SQL)
ou `false` : au moindre écart de type, chaque fichier d'un run déclenchait un `upgradeRow()` inutile, et toute
exception bloquait la ligne à jamais. **(3)** La politique « jamais plus gros » était **contradictoire** entre
ses deux implémentations : `ImageProcessor` **supprimait** le WebP perdant, `HtmlRewriter` le **conservait** en
cache négatif — donc le rattrapage supprimait, le rendu suivant ré-encodait (en consommant le budget on-demand),
un rattrapage ultérieur resupprimait, etc. Les deux conservent désormais le fichier.

**⚠️ À savoir sur la configuration par défaut** : `$wgVaultTecMediaOptimizerWebPLosslessForPng = true` combiné à
la politique « jamais plus gros » signifie qu'une **image à aplats** (icône, élément d'interface, dégradé lisse)
n'obtient **aucun WebP servi** — mesuré sur la fixture du pipeline : PNG 1 150 o contre WebP lossless 5 614 o,
soit 5× plus gros. C'est le comportement correct (le visiteur ne doit jamais télécharger davantage), mais si une
bonne part de vos médias est de ce type, le gain WebP se concentrera sur les contenus **photographiques**
(captures, artworks), où le rapport s'inverse nettement (mesuré : WebP ~3,5× plus petit).

### Fixed
- **`repairCorruptedFilenames.php` no longer calls an `@internal` core API** — it was the only place still using
  the legacy `$dbr->select()`, which MediaWiki now marks `@internal` (verified against core 1.46.0): it is not
  deprecated, so it raises no warning, but it is no longer intended for extensions and can be withdrawn without
  a deprecation cycle. Rewritten with `newSelectQueryBuilder()` + `IExpression::LIKE`/`LikeValue`, matching the
  rest of the extension.
- **Self-inflicted stats-cache regression** — `invalidateStatsCache()` was added in 1.9.0 to
  `markComplete/markFailed/markSkipped`, the per-file backfill write path, contradicting the explicit in-code
  decision ("we intentionally do NOT invalidate the stats cache on every thumbnail (could be very frequent).
  The 15s TTL handles freshness."). `WANObjectCache::delete()` is a broadcast purge that opens a hold-off
  window, so a running backfill kept the cache permanently held off and the auto-refreshing dashboard
  recomputed the full aggregates on every load. Reverted on those three; kept on the discrete actions
  (`delete()`, `resetFailedToPending()`); the misleading docblock now states the real policy and why.
- **Fragile strict comparison in `ZopfliOriginalProcessor`** — the stale-size detection used
  `$file->getSize() !== $afterSize`. `getSize()` may return a string (DB column) or `false` (stat failure), in
  which case the strict comparison reports "changed" for *every* file: an unconditional
  `purgeCache()`+`upgradeRow()` on every row of a bulk run, and any row whose refresh throws is never marked
  and retried forever. Now compared as integers, with a numeric guard.
- **Never-serve-larger was implemented inconsistently** — `ImageProcessor` deleted a losing WebP while
  `HtmlRewriter` deliberately kept it as a negative cache. The backfill therefore deleted the file, the next
  page render re-encoded it (spending a unit of the on-demand budget that legitimate thumbnails need),
  discovered again that it loses, and a later backfill deleted it again. Both paths now keep the file: the
  cheap "exists but not smaller" check short-circuits forever after. The row still records `webp = 0`, and
  `HtmlRewriter`'s serve-time size comparison remains the actual guarantee.

### Tests
- **`tests/integration/pipeline.php` — the never-serve-larger guarantee is now actually tested** (new step 3c):
  the thumbnail's WebP is inflated past its source and the **real** `HtmlRewriter` is asserted to emit no
  `<picture>`, to leave the HTML untouched, and to keep the losing file on disk. That guarantee previously had
  no coverage at all.
- **Pipeline fixture switched from a smooth gradient to photographic content** — the gradient's lossless WebP is
  5× *larger* than its PNG, so under the 1.9.0 policy the serve path silently stopped being exercised and three
  assertions had been failing unnoticed (they were masked by only reading the run's last line). The fixture now
  exercises the nominal "WebP wins and is served" path, while step 3c covers the refusal. Suite: `gif` 17/17,
  `gifAnimatedWebp` 26/26, `pipeline` 20/20.

## [1.9.0]

### 🇫🇷 Résumé
Correctifs issus d'un audit complet vérifié en conditions réelles. Les plus importants : **(1)** supprimer une
**ancienne version** d'un fichier ne détruit plus le WebP (et le suivi) du fichier **courant** — le hook ignorait
`$oldimage`. **(2)** Le **détecteur de GIF animé** est réécrit en vrai parseur de blocs (en flux) : l'ancienne
heuristique regex ratait des GIF animés légaux (sans extension de boucle, ou sans GCE), qui étaient alors
**flattés en WebP figé** sous GD — prouvé sur fichiers réels. **(3)** Au **réupload**, les WebP de vignettes de
l'ancienne image (mêmes chemins) sont purgés au lieu d'être servis indéfiniment. **(4)** Sous GD, les **PNG
16-bit** (tronqués en 4-bit, vérifié) et les **JPEG à orientation EXIF** (tag perdu sans rotation) sont désormais
refusés au lieu d'être dégradés en place. **(5)** Statistiques réparables sur **SQLite** (`LEAST()` n'y existe
pas). **(6)** La version MediaWiki minimale passe à **1.44** (les classes `MediaWiki\JobQueue\*` utilisées
n'existent que depuis 1.44 ; sur 1.43 chaque upload fatalait). Plus : la 2e passe ne marque plus une ligne
« terminée » si la resynchronisation des métadonnées MediaWiki échoue, `repairCorruptedFilenames` vérifie le
**SHA-1 en base** avant tout rename, et `$wgVaultTecMediaOptimizerFormats` est enfin restreignable
(`merge_strategy`).

**Nouvelle politique « jamais plus gros »** : un WebP qui n'est pas **strictement plus petit** que le fichier
qu'il remplace n'est **jamais servi**. Un WebP lossless d'un PNG déjà bien compressé (ou le WebP d'un GIF
minuscule à aplats) ressort couramment **plus gros** que la source : le servir faisait télécharger **plus**
d'octets au visiteur. Désormais : **(a)** au rendu, le rewriter compare les tailles et ne référence le WebP dans
`<picture>` que s'il est strictement plus petit — c'est la garantie, valable aussi pour les fichiers déjà sur
disque ; **(b)** à l'optimisation d'un original, un WebP non plus petit est **supprimé** et la ligne enregistrée
« complete » avec WebP = 0 (comme un GIF animé sans WebP) ; **(c)** un WebP de vignette plus gros est conservé
sur disque comme **cache négatif** (pas de ré-encodage à chaque rendu, le budget on-demand n'est dépensé qu'une
fois) mais jamais référencé.

**Durcissement « admin qui fait n'importe quoi »** (audit de mauvaise utilisation, gardes vérifiées sur la vraie
classe) : **(a)** `$wgVaultTecMediaOptimizerWebPDirectory` est désormais **validé** — vide, `..`, séparateurs de
chemin, ou **le même nom que le dossier d'upload** (cas où la purge des vignettes WebP aurait visé les
'''vraies''' vignettes de MediaWiki !) sont rejetés avec repli sur `images_webp` et erreur loggée ; **(b)** le
budget on-demand est **plafonné à 100** par rendu (une valeur absurde ne peut plus transformer un rendu à froid
en déni de service auto-infligé) ; **(c)** la **qualité WebP est bornée à 0–100** partout (GD jette une exception
sur une valeur négative, vips encodait silencieusement en bouillie) ; **(d)** les 4 scripts de maintenance qui
réécrivent des fichiers **préviennent s'ils tournent en root** (fichiers devenant root:root = réécritures
impossibles ensuite) ; **(e)** la page de rattrapage **avertit** quand `UseJobQueue=false` (les jobs planifiés
resteraient en file sans explication) ; **(f)** des entrées `Formats` invalides (« png » au lieu de
« image/png ») sont signalées sur la page d'état au lieu d'un double échec silencieux.

### Changed
- **Config hardening against careless values** —
  `WebPDirectory` is validated in `WebPRepo` (empty, `.`/`..`, path separators, NUL, or a value equal to the
  upload directory's basename — which would have aimed `deleteWebPThumbDir()` at MediaWiki's REAL thumbnail
  tree — fall back to `images_webp`, logged as an error); the on-demand render budget is hard-capped at 100;
  `WebPQuality` is clamped to 0–100 at every read (GD throws a `ValueError` on negatives — verified; vips
  silently produces garbage quality at 0); maintenance scripts that rewrite files print a loud warning when
  run as root (`posix_geteuid`); `Special:VTMOBackfill` shows a warning box when
  `$wgVaultTecMediaOptimizerUseJobQueue` is disabled (scheduled jobs would otherwise sit "Queued" forever with
  no explanation); `Special:VTMOStatus` flags `Formats` entries that are not full MIME types (a short name like
  "png" silently matched nothing AND dropped the backfill's SQL filter); `MaxFileSize = 0` is now documented as
  "no limit". The misuse audit also explicitly verified as already-safe: double-click/PRG on the backfill forms,
  manual deletion or chmod of `images_webp/` while live, concurrent CLI runs, Ctrl+C atomicity, shell
  metacharacters and `-`-leading filenames, XSS through file names, and a malicious `VipsBinary` (argv-array
  proc_open: file paths are never passed to a foreign binary as deletion targets).
- **Never-serve-larger policy** — a WebP is only ever delivered when it is **strictly smaller** than the file it
  replaces. Enforced at serve time in `HtmlRewriter` (size comparison before emitting the `<source>`; applies to
  pre-existing files too, so even a stale larger WebP is never referenced) and at generation time in
  `ImageProcessor` (a not-smaller WebP of an original is deleted and the row recorded complete with `webp = 0`,
  mirroring the animated-GIF-without-WebP outcome). Thumbnail WebPs that come out larger are deliberately kept
  on disk as a negative cache — the skip-if-exists guard and the on-demand budget are not re-spent every
  render — but are never referenced in `<picture>`. When the source file is missing on disk the comparison is
  impossible and the WebP is served as before: the guard only refuses when it can prove the WebP is not smaller.
- **`tests/integration/gifAnimatedWebp.php` extended (25 checks)** — now asserts both sides of the policy over
  real files: a flat-color still GIF whose WebP encodes larger ends up with the WebP discarded and `webp = 0`
  recorded, while a photo-like (plasma gradient) GIF whose WebP is genuinely smaller keeps it, with the served
  derivative verified strictly smaller than the original.

### Fixed
- **Deleting an OLD file version no longer destroys the live file's WebP and DB record** — `onFileDeleteComplete`
  ignored `$oldimage` (non-null when only an old revision is deleted) and unconditionally removed the current
  original's WebP and its `vtmo_image_optimization` row. Early-return added.
- **Animated-GIF detector rewritten as a structural block parser** — the regex heuristic required a `0x00` byte
  before each Graphic Control Extension, so animated GIFs without a NETSCAPE loop extension (and multi-frame GIFs
  without GCEs, which are legal) were reported as still and flattened to a one-frame WebP under GD. Verified
  against real files. The parser streams the file (constant memory, stops at the second Image Descriptor),
  mirroring core's `GIFMetadataExtractor` approach.
- **Stale WebP thumbnails after reupload** — thumbnails are regenerated at the same paths, and the
  skip-if-exists guard kept serving WebP rendered from the previous image's pixels forever. New
  `WebPRepo::deleteWebPThumbDir()` purges the file's `images_webp/thumb/.../` directory on reupload and on full
  deletion.
- **GD degraded originals in place** — verified: a 16-bit PNG came back 4-bit through GD's re-encode, and
  `imagejpeg()` drops the EXIF Orientation tag without rotating pixels (sideways camera photos). `GdOptimizer`
  now refuses PNGs with bit depth > 8 and JPEGs carrying Orientation > 1; Imagick/libvips deployments are
  unaffected.
- **`repairOptimizedSize()` crashed on SQLite** — `LEAST()` does not exist there (verified: "no such function").
  The clamp is now computed in PHP, portable across all supported backends.
- **MediaWiki requirement corrected to >= 1.44** — the extension uses `MediaWiki\JobQueue\Job`,
  `JobSpecification` and `JobQueueGroup`, whose namespaced forms only exist since 1.44 (core aliases are marked
  "since 1.44"); on a 1.43 wiki every upload fataled with "class not found".
- **Second-pass metadata desync can no longer hide behind a "done" marker** — `ZopfliOriginalProcessor` marked
  the row recompressed *before* refreshing MediaWiki's `img_sha1`/`img_size`, and swallowed a refresh failure;
  the row was never reselected, leaving stale metadata forever. The refresh now runs first; on failure the row
  stays pending and is retried (the retry also detects and repairs a stale size from a previous failed run).
- **`repairCorruptedFilenames.php` hardened** — before renaming, the candidate's SHA-1 is checked against the
  DB's `img_sha1` (a true mojibake victim is byte-identical, so this turns the name heuristic into proof and
  prevents installing the wrong file under a canonical name); the target's existence is re-checked immediately
  before `rename()` (no TOCTOU clobber of a reappeared file); a failed reversal-log write is now reported.
- **WebP URL corrupted for image URLs carrying a query string** — the origin-prefix length arithmetic in
  `WebPRepo::getWebPUrlAndPath()` counted the stripped query string, yielding URLs like `/ima/images_webp/...`
  (verified). The origin is now taken as the exact prefix preceding the URL path.
- **`$wgVaultTecMediaOptimizerFormats` can now be restricted** — without a `merge_strategy`, extension.json
  defaults merge with `array_merge`, so a LocalSettings restriction to e.g. PNG-only was silently ignored.
  Now `provide_default`.
- **Upload hook no longer talks to the job queue synchronously** — `push()` ran inside the upload's PRESEND
  `AutoCommitUpdate` with no try/catch; a queue-backend outage would abort core's own thumbnail/CDN purges that
  follow the hook. Switched to `lazyPush()` wrapped in try/catch.
- **Stats cache invalidation** — `markComplete/markFailed/markSkipped` (the dominant write path during a
  backfill) now invalidate the dashboard stats cache, as the docblock always claimed.
- **On-demand WebP size guard symmetry** — after an on-demand encode, a 0-byte result (disk full mid-write) is
  no longer referenced in `<picture>` (a broken `<source>` has no fallback to the inner `<img>`).
- **Traversal guard on `getWebPPath()`'s fallback branch** — the non-realpath branch only prefix-checked the
  upload dir, letting a `../` path slip through to `deleteWebP()`'s unlink. Now rejected like in
  `getWebPUrlAndPath()`. Docblocks also fixed to document the 3-tuple return.

## [1.8.4]

### 🇫🇷 Résumé
Deux robustesses issues d'un audit en conditions réelles sur un gros wiki. **(1)** Un GIF **animé** que le moteur
n'arrive pas à encoder en WebP animé (certains builds libvips/libwebp refusent les animations trop longues/grosses)
n'est plus traité comme un **échec** : on conserve le GIF d'origine (déjà optimisé `gifsicle`, sans perte) et on
enregistre un succès **sans WebP** — exactement comme la garde GIF animé du backend GD. L'animation reste servie.
**(2)** Nouveau script `repairCorruptedFilenames.php` pour réparer les **originaux dont le nom de fichier sur le
disque a dérivé** de celui en base (mojibake hérité d'une migration), que MediaWiki **et** l'optimiseur signalent
en « file not found ». Appariement prudent par *squelette ASCII* + unicité, dry-run par défaut.

### Fixed
- **Animated GIF → WebP failure is no longer a hard error** — when the selected engine cannot encode a given
  animated GIF to animated WebP (e.g. older libvips/libwebp rejecting large/long animations), `ImageProcessor`
  now keeps the lossless gifsicle-optimized original and records **complete with no WebP** instead of `failed`,
  mirroring the existing GD animated-GIF guard. The animation keeps being served; only its WebP derivative is
  skipped for that file.

### Added
- **`maintenance/repairCorruptedFilenames.php`** — repairs local originals whose physical on-disk filename no
  longer matches the DB name (Unicode/mojibake from a past migration), which makes both MediaWiki and the
  optimizer report "file not found". Conservative: matches a single same-directory file by its ASCII skeleton
  (drops bytes ≥ 0x80 and `%XX` escapes), renames only on an unambiguous match, never overwrites, dry-run unless
  `--apply`, and logs every rename for reversal. Options: `--apply`, `--format`, `--limit`.

## [1.8.3]

### 🇫🇷 Résumé
Corrige une **incompatibilité avec libvips < 8.12** (ex. 8.10.5 de Debian Bullseye). L'optimiseur vips passait
en dur `--effort 6` à `webpsave`, option **renommée** (`--reduction-effort` → `--effort`) seulement en libvips
8.12 : sur un build plus ancien, *chaque* encodage WebP via vips échouait avec « Unknown option --effort »
(`vips webpsave exited with code 1`), bloquant notamment **toutes les conversions de GIF animés**. L'extension
**détecte désormais une fois** le nom d'option accepté par le binaire (`--effort`, sinon `--reduction-effort`,
sinon aucun) et l'utilise. Aucune image n'était corrompue par ce bug : un échec d'encodage n'écrit aucun WebP,
donc l'original (GIF animé compris) restait servi tel quel.

### Fixed
- **libvips < 8.12 compatibility (`webpsave --effort`)** — `VipsOptimizer` hard-coded `--effort 6`, an option
  that libvips only renamed from `--reduction-effort` to `--effort` in 8.12. On older builds (e.g. 8.10.5 on
  Debian Bullseye) every vips WebP encode aborted with "Unknown option --effort", which surfaced as
  `vips webpsave exited with code 1` and broke **all animated-GIF → WebP** conversions when the `vips` engine was
  selected. The encoder now **probes once** which flag name the binary accepts (preferring `--effort`, falling
  back to `--reduction-effort`, then to no flag = libwebp's default effort) and reuses it. No data was ever at
  risk: a failed encode writes no WebP, so the original asset kept being served.

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
- **Animated GIF turned into a still WebP under the GD backend** — GD can only decode a GIF's first
  frame. Since WebP serving is decided purely by the WebP file's presence on disk, a GD-encoded WebP
  replaced the animation with a frozen image. A new `GifAnimationDetector` now lets `GdOptimizer` refuse
  the conversion (no WebP written, original animated GIF kept) and lets `ImageProcessor` record such
  files as **complete with no WebP** instead of failing. Both the upload pipeline and on-demand
  thumbnail generation are covered. Imagick/libvips produce genuine animated WebP and are unaffected.
- **Still GIF → WebP failed under GD** — `imagewebp()` rejects palette images, so single-frame GIFs
  never got a WebP under GD. `GdOptimizer` now promotes GIF input to truecolor (preserving transparency
  as alpha) before encoding.

### Tests
- **`tests/integration/gifAnimatedWebp.php`** — drives the real `GifAnimationDetector`, `GdOptimizer`
  and `ImageProcessor` (GD backend) over real `gifsicle`/`convert`-generated GIFs: animated GIFs are
  kept intact with no WebP, still GIFs convert to a valid WebP.

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
