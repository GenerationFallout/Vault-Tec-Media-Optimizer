<!--
  Vault-Tec Media Optimizer — GitHub description (FR / EN)
  Full manual: see the v1.4.1 PDF guide + docs/NEW_FEATURES.md for everything since.
-->

# Vault-Tec Media Optimizer

<p align="center"><b>VAULT-TEC · MEDIA OPTIMIZER</b><br/>
MediaWiki extension — lossless image optimization + automatic WebP (and experimental AVIF) delivery</p>

> **MediaWiki ≥ 1.43 · PHP ≥ 8.1 · GPL-2.0-or-later**
> Distributed by the *Vault-Tec Archives* — https://fallout-wiki.com/Extension:VaultTecMediaOptimizer

---

## 🇫🇷 Français

**Vault-Tec Media Optimizer** allège les médias d'un wiki de deux façons complémentaires :

- **Optimisation sans perte** des originaux (PNG/JPEG, et GIF via `gifsicle`) — les pixels restent identiques, seul le poids disque baisse.
- **Génération WebP** (et **AVIF** en option expérimentale) servie automatiquement aux navigateurs via une balise `<picture>`, avec repli sur l'image d'origine. C'est le vrai gain : **bande passante** et vitesse pour les visiteurs.

Les nouveaux fichiers sont traités automatiquement ; un **rattrapage** (backfill) traite l'existant, en file de tâches **ou en ligne de commande**. Trois pages spéciales (diagnostic, rattrapage, statistiques) pilotent le tout.

### Fonctionnalités clés
- WebP servi via `<picture>` (avec `srcset` Retina), repli intact sur l'`<img>` d'origine.
- WebP des miniatures **à la création** *et* **à la demande** au rendu (couvre les tailles déjà sur disque).
- 2ᵉ passe PNG sans perte optionnelle : **zopflipng** ou **oxipng**.
- **Moteur d'image sélectionnable** : `auto` (Imagick→GD) ou **`vips`** (libvips, bien plus rapide/léger à grande échelle).
- **Mode gros wiki** : `UseJobQueue = false` + script CLI `optimizeImages.php` pour traiter sans saturer la file.
- Écritures **atomiques** (temps uniques + rename) : pas de fichier dérivé tronqué servi, même sous concurrence.
- Aucune dépendance cloud : tout tourne sur votre serveur.

### Installation rapide
```php
// LocalSettings.php
wfLoadExtension( 'VaultTecMediaOptimizer' );
```
```bash
php maintenance/run.php update --quick   # crée les tables
```
Vérifiez **Spécial:VaultTec_État** (tout au vert), puis lancez le rattrapage depuis **Spécial:VaultTec_Optimisation**.

### Voir aussi
- **Manuel complet** (installation + administration, v1.8.2, bilingue FR/EN, sections 1–16 + tutoriel, dépendances & benchmarks intégrés) : [`docs/VaultTecMediaOptimizer-Documentation.pdf`](docs/VaultTecMediaOptimizer-Documentation.pdf).
- **Performances, dépendances & benchmarks** : guide bilingue [`docs/VaultTecMediaOptimizer-Performances-et-Bonnes-Pratiques.pdf`](docs/VaultTecMediaOptimizer-Performances-et-Bonnes-Pratiques.pdf) (installation des dépendances, benchmarks coût/bénéfice honnêtes).
- **Nouveautés depuis la v1.4.1** (moteur libvips, GIF `gifsicle`, AVIF, scripts CLI, mode gros wiki, correctifs 1.8.x) : [`docs/NEW_FEATURES.md`](docs/NEW_FEATURES.md).
- Journal des modifications : [`CHANGELOG.md`](CHANGELOG.md).

---

## 🇬🇧 English

**Vault-Tec Media Optimizer** lightens a wiki's media in two complementary ways:

- **Lossless optimization** of originals (PNG/JPEG, and GIF via `gifsicle`) — pixels stay identical, only disk size drops.
- **WebP generation** (and **experimental AVIF**) served automatically through a `<picture>` tag, with a fallback to the original. This is the real win: **bandwidth** and speed for visitors.

New uploads are processed automatically; a **backfill** handles existing files, via the job queue **or the command line**. Three special pages (diagnostics, backfill, statistics) drive everything.

### Key features
- WebP served via `<picture>` (with Retina `srcset`); the original `<img>` stays as fallback.
- Thumbnail WebP **on creation** *and* **on demand** at render (covers sizes already on disk).
- Optional lossless second PNG pass: **zopflipng** or **oxipng**.
- **Selectable image engine**: `auto` (Imagick→GD) or **`vips`** (libvips, much faster/lighter at scale).
- **Large-wiki mode**: `UseJobQueue = false` + the `optimizeImages.php` CLI to process without flooding the queue.
- **Atomic** writes (unique temp + rename): no torn derived file is ever served, even under concurrency.
- No cloud dependency: everything runs on your server.

### Quick install
```php
// LocalSettings.php
wfLoadExtension( 'VaultTecMediaOptimizer' );
```
```bash
php maintenance/run.php update --quick   # creates the tables
```
Check **Special:VTMOStatus** (all green), then start the backfill from **Special:VTMOBackfill**.

### See also
- **Complete manual** (install + administration, v1.8.2, bilingual FR/EN, sections 1–16 + tutorial, dependencies & benchmarks included): [`docs/VaultTecMediaOptimizer-Documentation.pdf`](docs/VaultTecMediaOptimizer-Documentation.pdf).
- **Performance, dependencies & benchmarks**: bilingual guide [`docs/VaultTecMediaOptimizer-Performances-et-Bonnes-Pratiques.pdf`](docs/VaultTecMediaOptimizer-Performances-et-Bonnes-Pratiques.pdf) (dependency install, honest cost/benefit benchmarks).
- **What's new since v1.4.1** (libvips engine, GIF `gifsicle`, AVIF, CLI scripts, large-wiki mode, 1.8.x fixes): [`docs/NEW_FEATURES.md`](docs/NEW_FEATURES.md).
- Change log: [`CHANGELOG.md`](CHANGELOG.md).

---

## License
GPL-2.0-or-later — Vault-Tec Archives.
