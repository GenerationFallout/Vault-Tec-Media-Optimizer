# Vault-Tec Media Optimizer — Documentation supplement (since v1.4.1)

This supplement enriches the bundled v1.4.1 PDF guide with everything added afterwards. Section
numbers (e.g. *§4*) refer to the PDF. **Français** first, **English** below.

---

# 🇫🇷 Compléments à la documentation

## ⚡ 1.8.x — GIF sans perte, `--format` & correctifs (résumé)

**Optimisation GIF sans perte (`gifsicle`)** — `$wgVaultTecMediaOptimizerGifsicleEnabled = true;` (+ `…GifsicleBinary`, `…GifsicleLevel` 1–3, défaut 3). 1ʳᵉ passe, **strictement sans perte et sûre pour l'animation** (seul `-O{niveau}` ; jamais resize/lossy/colors). Tourne à l'upload **et** dans `optimizeImages.php` ; `gifsicle` absent = no-op inoffensif. Installation : `sudo apt install gifsicle`.

**Filtre `--format` (rattrapage CLI)** — `php maintenance/optimizeImages.php --format=gif` (ou `--format=png,jpeg`) : restreint le run à un sous-ensemble des formats configurés.

**Correctifs 1.8.1** — métadonnées MediaWiki (`img_sha1`/`img_size`) **resynchronisées** après la 1ʳᵉ passe en place ; **GIF animés jamais figés** : sous GD (1ʳᵉ frame seulement) le WebP/AVIF est refusé et le GIF animé conservé ; les GIF statiques se convertissent correctement (palette → truecolor).

**Correctif 1.8.2 (latence)** — le **2ᵉ passage zopflipng des miniatures** ne tourne plus dans le rendu : il est confié à un **job** (`VaultTecMediaOptimizerThumbnailRecompress`). Comme pour les originaux, traitez la file **hors requête** (`$wgJobRunRate = 0` + `runJobs.php`) pour qu'**aucun visiteur** ne paie ce traitement lent (~14 s/fichier). Sauté en mode gros wiki.

> 📊 **Benchmarks & coût/bénéfice honnête** (bande passante au niveau *miniature*, le WebP qui *ajoute* du disque, installation des dépendances) : voir le guide **Performances & Bonnes-Pratiques** — [`docs/VaultTecMediaOptimizer-Performances-et-Bonnes-Pratiques.pdf`](VaultTecMediaOptimizer-Performances-et-Bonnes-Pratiques.pdf) (PDF bilingue, 13 graphiques ; modèle reproductible : `docs/benchmarks/model.py`).

## A. Nouveaux paramètres de configuration (complète le §4)

| Paramètre | Défaut | Description |
|---|---|---|
| `$wgVaultTecMediaOptimizerImageEngine` | `auto` | Moteur de la 1ʳᵉ passe et de l'encodage WebP : `auto` (Imagick puis GD) ou `vips` (libvips, voir §B). Repli automatique sur Imagick/GD si vips est inutilisable. |
| `$wgVaultTecMediaOptimizerVipsBinary` | `vips` | Chemin ou nom de commande du binaire `vips`. Utilisé seulement si le moteur est `vips`. Nécessite l'exécution shell (`proc_open`/`exec`). |
| `$wgVaultTecMediaOptimizerUseJobQueue` | `true` | `false` = **mode gros wiki** : les uploads ne sont plus mis en file ; on optimise les originaux en CLI (voir §C). Les miniatures reçoivent quand même leur WebP à la demande au rendu. |
| `$wgVaultTecMediaOptimizerOnDemandThumbLimit` | `5` | Nombre maximal de WebP de miniatures **manquants** encodés en synchrone pendant **un seul** rendu de page (garde-fou de latence sur cache froid). `0` désactive la génération à la volée. |

> Les WebP **déjà présents** sont toujours servis ; ce plafond ne concerne que les WebP manquants
> encodés inline lors d'un rendu. Le reste se réchauffe aux vues suivantes.

## B. Moteur libvips (optionnel)

### Pourquoi
libvips encode le WebP et recompresse PNG/JPEG **bien plus vite et avec beaucoup moins de mémoire**
qu'ImageMagick (traitement en flux). **La qualité/taille du WebP est identique** (même libwebp) : le gain
est la **vitesse et la mémoire**, surtout pour un gros rattrapage et la génération à la volée. À privilégier
sur une grosse photothèque ; inutile sur un petit wiki.

### Installation du binaire `vips`
```bash
# Debian / Ubuntu
sudo apt install libvips-tools
# RHEL / Alma / Rocky / Fedora (EPEL)
sudo dnf install vips-tools
# Alpine (Docker)
apk add vips-tools
```
Vérifiez : `vips --version` et `vips -l | grep -i webpsave`.

> ⚠️ Même piège **open_basedir** que pour zopfli/oxipng (§7) : si PHP est restreint, placez `vips` dans
> l'arborescence du wiki et donnez le **chemin absolu** dans `VipsBinary`.

### Activation
```php
$wgVaultTecMediaOptimizerImageEngine = 'vips';
$wgVaultTecMediaOptimizerVipsBinary  = '/usr/bin/vips';   // adapter via `which vips`
```
Rechargez **Spécial:VaultTec_État** : la ligne « Moteur d'image » affiche `vips` et la ligne « Binaire
libvips » passe au vert. Si le binaire est introuvable, l'extension **retombe automatiquement** sur
Imagick/GD (aucune interruption).

## C. Mode « gros wiki » : traiter en ligne de commande (complète le §8.1)

Sur un gros wiki, planifier des dizaines de milliers de jobs (bouton « Tout planifier ») charge fortement la
file. Deux nouveautés permettent de **piloter le traitement en CLI**, sans saturer la file ni ralentir les
visiteurs.

### 1. Stopper l'enfilement à l'upload
```php
$wgVaultTecMediaOptimizerUseJobQueue = false;
```
Les nouveaux uploads ne créent plus de job. **Les miniatures reçoivent toujours leur WebP à la demande**
au rendu ; seul l'original attend votre passage CLI.

### 2. Backfill CLI direct — `optimizeImages.php`
Traite les originaux existants **inline** (1ʳᵉ passe + WebP), **hors file de jobs**, en respectant le moteur
configuré (donc la voie naturelle d'un backfill **libvips**) :
```bash
# Aperçu sans rien modifier
php maintenance/run.php extensions/VaultTecMediaOptimizer/maintenance/optimizeImages.php --dry-run
# Traiter par lots de 200
php maintenance/run.php .../optimizeImages.php --batch=200
```
| Option | Effet |
|---|---|
| `--dry-run` | Compte les fichiers éligibles, ne modifie rien. |
| `--batch=N` | Taille de lot de balayage (défaut 100). |
| `--max=N` | S'arrête après N fichiers optimisés. |
| `--force` | Retraite aussi les fichiers déjà `complete`/`skipped`. |
| `--start=Nom.png` | Reprend le balayage après ce nom (reprenable). |
| `--purge-queue` | Vide la file OptimizeImage **avant** de balayer (évite la redondance avec un backfill déjà planifié). |

Le balayage est paginé par `img_name` : **reprenable**, progression garantie, et chaque fichier en échec est
isolé (une exception n'interrompt pas tout le run).

### 3. Vider la file — `purgeQueue.php`
Si une planification précédente a laissé des jobs en file alors que vous passez en CLI :
```bash
php maintenance/run.php .../purgeQueue.php --dry-run        # compte
php maintenance/run.php .../purgeQueue.php                  # vide la file OptimizeImage
php maintenance/run.php .../purgeQueue.php --include-zopfli # + file 2e passe
```
La file zopfli (2ᵉ passe) est **préservée** par défaut, car le backfill CLi ne la remplace pas.

> **Choisissez une voie** pour un corpus donné : soit le rattrapage par file (`Spécial:VaultTec_Optimisation`
> + `runJobs.php`), soit le CLI (`optimizeImages.php`). Ne lancez pas les deux **en même temps** sur le même
> stock (utilisez `--purge-queue` au besoin).

## D. Robustesse des écritures (note technique)

Tous les fichiers dérivés (WebP/AVIF) sont écrits dans un **fichier temporaire à nom unique** puis publiés par
`rename()` **atomique**. Conséquences : un lecteur concurrent ne voit jamais un fichier à moitié écrit ; une
régénération qui échoue **ne supprime pas** un fichier valide existant ; et deux traitements simultanés du
même original ne peuvent plus se corrompre mutuellement (noms de temp uniques pour les optimiseurs **et**
les recompresseurs zopfli/oxipng). Par ailleurs, un dérivé **vide/tronqué** (reliquat de crash) est traité
comme manquant : il n'est pas servi et est régénéré.

---

# 🇬🇧 Documentation additions

## ⚡ 1.8.x — lossless GIF, `--format` & fixes (summary)

**Lossless GIF optimization (`gifsicle`)** — `$wgVaultTecMediaOptimizerGifsicleEnabled = true;` (+ `…GifsicleBinary`, `…GifsicleLevel` 1–3, default 3). First pass, **strictly lossless and animation-safe** (only `-O{level}`; never resize/lossy/colors). Runs on upload **and** in `optimizeImages.php`; a missing `gifsicle` is a harmless no-op. Install: `sudo apt install gifsicle`.

**`--format` filter (CLI backfill)** — `php maintenance/optimizeImages.php --format=gif` (or `--format=png,jpeg`): restrict a run to a subset of the configured formats.

**1.8.1 fixes** — MediaWiki metadata (`img_sha1`/`img_size`) **refreshed** after the in-place first pass; **animated GIFs are never frozen**: under GD (first frame only) the WebP/AVIF is refused and the animated GIF kept; still GIFs convert correctly (palette → truecolor).

**1.8.2 fix (latency)** — the **thumbnail zopflipng second pass** no longer runs during render: it is handed to a **job** (`VaultTecMediaOptimizerThumbnailRecompress`). As with originals, process the queue **off-request** (`$wgJobRunRate = 0` + `runJobs.php`) so **no visitor** pays for the slow pass (~14 s/file). Skipped in large-wiki mode.

> 📊 **Honest benchmarks & cost/benefit** (thumbnail-level bandwidth, WebP that *adds* disk, dependency install): see the **Performance & Best-Practices** guide — [`docs/VaultTecMediaOptimizer-Performances-et-Bonnes-Pratiques.pdf`](VaultTecMediaOptimizer-Performances-et-Bonnes-Pratiques.pdf) (bilingual PDF, 13 charts; reproducible model: `docs/benchmarks/model.py`).

## A. New configuration settings (extends §4)

| Setting | Default | Description |
|---|---|---|
| `$wgVaultTecMediaOptimizerImageEngine` | `auto` | First-pass and WebP encoding engine: `auto` (Imagick then GD) or `vips` (libvips, see §B). Automatic fallback to Imagick/GD if vips is unusable. |
| `$wgVaultTecMediaOptimizerVipsBinary` | `vips` | Path or command name of the `vips` binary. Used only when the engine is `vips`. Requires shell execution (`proc_open`/`exec`). |
| `$wgVaultTecMediaOptimizerUseJobQueue` | `true` | `false` = **large-wiki mode**: uploads are no longer enqueued; optimize originals via the CLI (see §C). Thumbnails still get WebP on demand at render. |
| `$wgVaultTecMediaOptimizerOnDemandThumbLimit` | `5` | Max number of **missing** thumbnail WebP files encoded synchronously during **one** page render (cold-cache latency guard). `0` disables on-demand generation. |

> Existing WebP files are always served; this cap only applies to missing WebP encoded inline during a
> render. The rest warms up on later views.

## B. The libvips engine (optional)

### Why
libvips encodes WebP and recompresses PNG/JPEG **much faster and with far less memory** than ImageMagick
(streaming pipeline). **WebP quality/size is identical** (same libwebp): the win is **speed and memory**,
mainly for a large backfill and on-demand generation. Worth it on a large media library; pointless on a small
wiki.

### Installing the `vips` binary
```bash
sudo apt install libvips-tools     # Debian/Ubuntu
sudo dnf install vips-tools        # RHEL/Alma/Rocky/Fedora (EPEL)
apk add vips-tools                 # Alpine (Docker)
```
Verify: `vips --version` and `vips -l | grep -i webpsave`.

> ⚠️ Same **open_basedir** pitfall as zopfli/oxipng (§7): if PHP is restricted, place `vips` inside the wiki
> tree and set the **absolute path** in `VipsBinary`.

### Enabling
```php
$wgVaultTecMediaOptimizerImageEngine = 'vips';
$wgVaultTecMediaOptimizerVipsBinary  = '/usr/bin/vips';
```
Reload **Special:VTMOStatus**: "Image engine" shows `vips` and "libvips binary" turns green. If the binary is
not found, the extension **falls back automatically** to Imagick/GD (no interruption).

## C. Large-wiki mode: CLI processing (extends §8.1)

On a large wiki, scheduling tens of thousands of jobs ("Schedule all") heavily loads the queue. Two additions
let you **drive processing from the CLI** without flooding the queue or slowing visitors.

### 1. Stop enqueuing on upload
```php
$wgVaultTecMediaOptimizerUseJobQueue = false;
```
New uploads no longer create a job. **Thumbnails still get their WebP on demand** at render; only the
original waits for your CLI run.

### 2. Direct CLI backfill — `optimizeImages.php`
Optimizes existing originals **inline** (first pass + WebP) **outside the job queue**, honouring the configured
engine (the natural way to run a **libvips** backfill):
```bash
php maintenance/run.php extensions/VaultTecMediaOptimizer/maintenance/optimizeImages.php --dry-run
php maintenance/run.php .../optimizeImages.php --batch=200
```
Options: `--dry-run`, `--batch=N`, `--max=N`, `--force` (redo complete rows), `--start=Name.png` (resume),
`--purge-queue` (clear the OptimizeImage queue first). The scan is paginated by `img_name`: resumable, with
guaranteed forward progress, and each failing file is isolated (one exception never ends the whole run).

### 3. Purge the queue — `purgeQueue.php`
```bash
php maintenance/run.php .../purgeQueue.php --dry-run        # report counts
php maintenance/run.php .../purgeQueue.php                  # empty the OptimizeImage queue
php maintenance/run.php .../purgeQueue.php --include-zopfli # also the second-pass queue
```
The zopfli (second-pass) queue is **kept** by default, since the CLI backfill does not replace it.

> **Pick one path** per corpus: either queue-based backfill (`Special:VTMOBackfill` + `runJobs.php`) or the
> CLI (`optimizeImages.php`). Don't run both **at once** on the same stock (use `--purge-queue` if needed).

## D. Write robustness (technical note)

All derived files (WebP/AVIF) are written to a **unique-name** temporary file then published via an **atomic**
`rename()`. As a result: a concurrent reader never sees a half-written file; a failed regeneration does **not**
delete an existing good file; and two simultaneous runs on the same original can no longer corrupt each other
(unique temp names for the optimizers **and** the zopfli/oxipng recompressors). An empty/truncated derived
file (crash leftover) is treated as missing: not served, and regenerated.
