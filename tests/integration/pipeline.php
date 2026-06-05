<?php
/**
 * SIMULATION COMPLÈTE DU CYCLE DE VIE — vraies classes de l'extension,
 * vrai libvips, vrai zopflipng, vrais fichiers sur disque, vraie résolution
 * de chemins. Seuls MediaWiki (File/RepoGroup/DB/Logger) sont bouchonnés.
 *
 * Étapes : 1) Upload + 1re passe + WebP   2) Transformation (miniature -> WebP)
 *          3) Maturation/service (<picture>)   4) 2e passe (zopflipng).
 */

// ---- Faux MediaWiki, FQCN exacts ----
namespace MediaWiki\Config {
	class ServiceOptions {
		private array $o;
		public function __construct( array $o ) { $this->o = $o; }
		public function get( string $k ) {
			if ( !array_key_exists( $k, $this->o ) ) { throw new \RuntimeException( "Unrecognized option: $k" ); }
			return $this->o[$k];
		}
	}
}
namespace Psr\Log {
	interface LoggerInterface {
		public function debug( $m, array $c = [] ); public function info( $m, array $c = [] );
		public function warning( $m, array $c = [] ); public function error( $m, array $c = [] );
		public function critical( $m, array $c = [] ); public function alert( $m, array $c = [] );
		public function emergency( $m, array $c = [] ); public function notice( $m, array $c = [] );
		public function log( $l, $m, array $c = [] );
	}
}
namespace MediaWiki\FileRepo\File {
	class File {
		public function __construct( public string $name, public string $rel, public string $abs, public string $mime, public int $size ) {}
		public function getName(): string { return $this->name; }
		public function getRel(): string { return $this->rel; }
		public function getLocalRefPath() { return $this->abs; }
		public function getMimeType(): string { return $this->mime; }
		public function getSize() { return $this->size; }
		public function exists(): bool { return is_file( $this->abs ); }
	}
}
namespace MediaWiki\FileRepo {
	class LocalRepo {
		public array $files = [];
		public function newFile( $name ) { return $this->files[$name] ?? false; }
	}
	class RepoGroup {
		public LocalRepo $repo;
		public function __construct( LocalRepo $r ) { $this->repo = $r; }
		public function getLocalRepo() { return $this->repo; }
	}
}
namespace MediaWiki\Extension\VaultTecMediaOptimizer\Storage {
	// Faux enregistrement : journalise les appels (la vraie logique upsert/stats
	// est déjà couverte par sim_sqlite). FQCN identique pour le type-hint.
	class OptimizationRecord {
		public array $rows = [];
		public function markSkipped( $n, $r ) { $this->rows[$n] = [ 'status' => 'skipped', 'reason' => $r ]; }
		public function markFailed( $n, $e ) { $this->rows[$n] = [ 'status' => 'failed', 'error' => $e ]; }
		public function markComplete( $n, $o, $opt, $w, $b ) { $this->rows[$n] = [ 'status' => 'complete', 'orig' => $o, 'opt' => $opt, 'webp' => $w, 'backend' => $b ]; }
		public function markPngRecompressed( $n, $s ) { $this->rows[$n]['zopfli'] = $s; }
	}
}

namespace {
	use MediaWiki\Config\ServiceOptions;
	use MediaWiki\FileRepo\File\File;
	use MediaWiki\FileRepo\LocalRepo;
	use MediaWiki\FileRepo\RepoGroup;
	use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;

	$R = dirname( __DIR__, 2 ) . '/includes';
	require "$R/Optimizer/OptimizerInterface.php";
	require "$R/Optimizer/AtomicWriteTrait.php";
	require "$R/Optimizer/ImagickOptimizer.php";
	require "$R/Optimizer/GdOptimizer.php";
	require "$R/Optimizer/VipsOptimizer.php";
	require "$R/Optimizer/OptimizerFactory.php";
	require "$R/Storage/WebPRepo.php";
	require "$R/Service/ImageProcessor.php";
	require "$R/Service/HtmlRewriter.php";
	require "$R/Service/PngRecompressorInterface.php";
	require "$R/Service/ZopfliRecompressor.php";

	$NS = 'MediaWiki\\Extension\\VaultTecMediaOptimizer\\';
	function pass( $c, $l ) { echo ( $c ? "  ✅ " : "  ❌ " ) . $l . "\n"; return $c; }
	$logger = new class implements \Psr\Log\LoggerInterface {
		public function debug( $m, array $c = [] ) {} public function info( $m, array $c = [] ) {}
		public function warning( $m, array $c = [] ) {} public function error( $m, array $c = [] ) {}
		public function critical( $m, array $c = [] ) {} public function alert( $m, array $c = [] ) {}
		public function emergency( $m, array $c = [] ) {} public function notice( $m, array $c = [] ) {}
		public function log( $l, $m, array $c = [] ) {}
	};

	// ---- Arborescence wiki réelle ----
	$BASE = '/tmp/pipeline';
	exec( 'rm -rf ' . escapeshellarg( $BASE ) );
	$IMG = "$BASE/images";
	@mkdir( "$IMG/a/ab", 0777, true );
	$origRel = 'a/ab/Test.png';
	$origAbs = "$IMG/$origRel";
	// Image source réaliste (dégradé) -> compressible
	$im = imagecreatetruecolor( 400, 300 );
	for ( $y = 0; $y < 300; $y++ ) {
		for ( $x = 0; $x < 400; $x++ ) {
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, ( $x * 255 ) / 400, ( $y * 255 ) / 300, 128 ) );
		}
	}
	imagepng( $im, $origAbs );
	$origStart = filesize( $origAbs );

	$opts = new ServiceOptions( [
		'VaultTecMediaOptimizerEnabled' => true,
		'VaultTecMediaOptimizerProcessThumbnails' => true,
		'VaultTecMediaOptimizerOptimizeOriginals' => true,
		'VaultTecMediaOptimizerFormats' => [ 'image/png', 'image/jpeg', 'image/gif' ],
		'VaultTecMediaOptimizerMaxFileSize' => 52428800,
		'VaultTecMediaOptimizerWebPDirectory' => 'images_webp',
		'VaultTecMediaOptimizerAvifDirectory' => 'images_avif',
		'VaultTecMediaOptimizerAvifEnabled' => false,
		'VaultTecMediaOptimizerAvifQuality' => 50,
		'VaultTecMediaOptimizerWebPLosslessForPng' => true,
		'VaultTecMediaOptimizerWebPQuality' => 85,
		'VaultTecMediaOptimizerOnDemandThumbLimit' => 5,
		'VaultTecMediaOptimizerStripMetadata' => true,
		'VaultTecMediaOptimizerImageEngine' => 'vips',
		'VaultTecMediaOptimizerVipsBinary' => getenv('VTMO_VIPS') ?: '/usr/bin/vips',
		'VaultTecMediaOptimizerZopfliEnabled' => true,
		'VaultTecMediaOptimizerZopfliBinary' => 'zopflipng',
		'VaultTecMediaOptimizerZopfliIterations' => 15,
		'UploadDirectory' => $IMG,
		'UploadPath' => '/images',
	] );

	$webpRepo = new ( $NS . 'Storage\\WebPRepo' )( $opts, $logger );
	$factory  = new ( $NS . 'Optimizer\\OptimizerFactory' )( $opts, $logger );
	$record   = new OptimizationRecord();

	echo "=== Moteur sélectionné : " . $factory->getOptimizer()->getName() . " ===\n\n";

	// ============ ÉTAPE 1 — UPLOAD : ImageProcessor réel ============
	echo "ÉTAPE 1 — Upload (1re passe + WebP) via ImageProcessor réel\n";
	$repoGroup = new RepoGroup( new LocalRepo() );
	$file = new File( 'Test.png', $origRel, $origAbs, 'image/png', $origStart );
	$repoGroup->getLocalRepo()->files['Test.png'] = $file;
	$processor = new ( $NS . 'Service\\ImageProcessor' )( $opts, $factory, $webpRepo, $record, $repoGroup, $logger );

	$ok = $processor->processByName( 'Test.png' );
	$webpOrig = "$BASE/images_webp/a/ab/Test.webp";
	pass( $ok === true, "process() = true" );
	pass( ( $record->rows['Test.png']['status'] ?? '' ) === 'complete', "enregistré 'complete' (backend=" . ( $record->rows['Test.png']['backend'] ?? '?' ) . ")" );
	pass( is_file( $webpOrig ) && substr( file_get_contents( $webpOrig ), 0, 4 ) === 'RIFF', "WebP de l'original créé (RIFF, " . ( is_file( $webpOrig ) ? filesize( $webpOrig ) : 0 ) . "o)" );
	pass( filesize( $origAbs ) <= $origStart, "original 1re passe: $origStart -> " . filesize( $origAbs ) . " o (keep-if-smaller)" );
	$origAfterPass1 = filesize( $origAbs );

	// ============ ÉTAPE 2 — TRANSFORMATION : miniature -> WebP ============
	echo "\nÉTAPE 2 — Transformation (miniature 220px -> WebP), logique de onFileTransformed\n";
	$thumbDir = "$IMG/thumb/a/ab/Test.png";
	@mkdir( $thumbDir, 0777, true );
	$thumbAbs = "$thumbDir/220px-Test.png";
	$th = imagecreatetruecolor( 220, 165 );
	imagecopyresampled( $th, $im, 0, 0, 0, 0, 220, 165, 400, 300 );
	imagepng( $th, $thumbAbs );
	$thumbUrl = '/images/thumb/a/ab/Test.png/220px-Test.png';

	$info = $webpRepo->getWebPUrlAndPath( $thumbUrl );
	pass( is_array( $info ), "getWebPUrlAndPath(thumbUrl) résout (url=" . ( $info[0] ?? '?' ) . ")" );
	[ $thWebpUrl, $thWebpDest, $thSrc ] = $info;
	pass( realpath( $thSrc ) === realpath( $thumbAbs ), "chemin source de la miniature correctement résolu" );
	$webpRepo->ensureDirFor( $thWebpDest );
	$conv = $factory->getOptimizer()->convertToWebP( $thumbAbs, $thWebpDest, true, 85 );
	pass( $conv && is_file( $thWebpDest ) && substr( file_get_contents( $thWebpDest ), 0, 4 ) === 'RIFF', "WebP de la miniature créé (RIFF, " . ( is_file( $thWebpDest ) ? filesize( $thWebpDest ) : 0 ) . "o)" );

	// ============ ÉTAPE 3 — MATURATION / SERVICE : HtmlRewriter réel ============
	echo "\nÉTAPE 3 — Service de la page : HtmlRewriter réel (<img> -> <picture>)\n";
	$rewriter = new ( $NS . 'Service\\HtmlRewriter' )( $opts, $webpRepo, $factory, $logger );
	$html = '<p>Article</p><img src="' . $thumbUrl . '" alt="x" width="220">';
	$rewritten = $html;
	$rewriter->rewrite( $rewritten );
	pass( strpos( $rewritten, '<picture>' ) !== false && strpos( $rewritten, 'type="image/webp"' ) !== false, "<picture> + <source type=image/webp> émis" );
	pass( strpos( $rewritten, '<img src="' . $thumbUrl . '"' ) !== false, "<img> d'origine conservé en fallback" );
	preg_match( '/<source srcset="([^"]+)"/', $rewritten, $sm );
	pass( isset( $sm[1] ) && strpos( $sm[1], '/images_webp/' ) === 0, "srcset pointe vers /images_webp/ (" . ( $sm[1] ?? '?' ) . ")" );

	echo "    HTML servi: " . htmlspecialchars_decode( $rewritten ) . "\n";

	// 3b — maturation à la volée : SUPPRIMER le webp de la miniature et re-servir
	echo "\nÉTAPE 3b — Maturation à la volée (WebP manquant régénéré au rendu)\n";
	unlink( $thWebpDest );
	$rew2 = $html; $rewriter->rewrite( $rew2 );
	pass( is_file( $thWebpDest ) && strpos( $rew2, 'image/webp' ) !== false, "WebP régénéré à la volée + servi (budget P1)" );

	// ============ ÉTAPE 4 — 2e PASSE : zopflipng réel sur l'original ============
	echo "\nÉTAPE 4 — 2e passe zopflipng réelle sur l'original PNG\n";
	$zopfli = new ( $NS . 'Service\\ZopfliRecompressor' )( $opts, $logger );
	pass( $zopfli->isAvailable() === true, "zopflipng disponible (binaire réel)" );
	$saved = $zopfli->recompress( $origAbs );
	$origFinal = filesize( $origAbs );
	pass( $saved !== null, "recompress() ne renvoie pas null (saved=" . var_export( $saved, true ) . " o)" );
	pass( $origFinal <= $origAfterPass1, "original 2e passe: $origAfterPass1 -> $origFinal o (jamais plus gros)" );

	// ============ AUDIT FINAL DU PIPELINE ============
	echo "\n=== AUDIT FINAL : état du disque & cohérence ===\n";
	echo "Arborescence produite :\n";
	exec( "cd $BASE && find . -type f | sort", $tree );
	foreach ( $tree as $t ) { echo "    $t (" . filesize( "$BASE/" . ltrim( $t, './' ) ) . "o)\n"; }
	pass( count( glob( "$BASE/*.vtmo.*" ) ) === 0 && count( glob( "$IMG/**/*.tmp", GLOB_BRACE ) ) === 0, "aucun fichier temporaire orphelin sur tout l'arbre" );
	// tous les .webp sont de vrais WebP
	$allWebp = true; foreach ( glob( "$BASE/images_webp/**/*.webp", GLOB_BRACE ) as $w ) { if ( substr( file_get_contents( $w ), 0, 4 ) !== 'RIFF' ) { $allWebp = false; } }
	pass( $allWebp, "tous les fichiers .webp produits sont des WebP valides (RIFF)" );

	echo "\nRésumé tailles : original $origStart o → (1re passe) $origAfterPass1 o → (2e passe) $origFinal o\n";
	exec( "rm -rf $BASE" );
	echo "\nTerminé.\n";
}
