<?php
/**
 * Experimental AVIF: "serve WebP when AVIF isn't smaller" + no re-encode loop.
 *
 * AVIF is not always smaller than WebP (content/quality/encoder dependent), and
 * a <picture> offers AVIF *before* WebP — so a larger AVIF would make capable
 * browsers download MORE than with WebP. This test drives the REAL ImageProcessor
 * (GD backend, AVIF enabled) and proves, per file:
 *
 *   - if the produced AVIF is >= its WebP  -> the AVIF is DISCARDED (not on disk),
 *     a ".skip" marker is written, and a second run does NOT re-encode it;
 *   - if the produced AVIF is  < its WebP  -> the AVIF is KEPT, no marker.
 *
 * The expected outcome per image is computed from real encodes (no hardcoding),
 * so the test is correct whichever way each image falls.
 *
 * Requires: PHP-GD with WebP AND AVIF support, ImageMagick `convert`.
 * Run:  php tests/integration/avifKeepSmaller.php
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Config {
	class ServiceOptions {
		private array $o;
		public function __construct( array $o ) {
			$this->o = $o;
		}
		public function get( $k ) {
			return $this->o[$k] ?? null;
		}
	}
}
namespace Psr\Log {
	if ( !interface_exists( \Psr\Log\LoggerInterface::class ) ) {
		interface LoggerInterface {
			public function debug( $m, array $c = [] );
			public function info( $m, array $c = [] );
			public function warning( $m, array $c = [] );
			public function error( $m, array $c = [] );
			public function critical( $m, array $c = [] );
			public function alert( $m, array $c = [] );
			public function emergency( $m, array $c = [] );
			public function notice( $m, array $c = [] );
			public function log( $l, $m, array $c = [] );
		}
	}
}
namespace MediaWiki\FileRepo\File {
	class File {
		public function __construct(
			public string $name, public string $rel, public string $abs,
			public string $mime, public int $size
		) {
		}
		public function getName(): string {
			return $this->name;
		}
		public function getRel(): string {
			return $this->rel;
		}
		public function getLocalRefPath() {
			return $this->abs;
		}
		public function getMimeType(): string {
			return $this->mime;
		}
		public function getSize() {
			return $this->size;
		}
		public function exists(): bool {
			return is_file( $this->abs );
		}
	}
}
namespace MediaWiki\FileRepo {
	class LocalRepo {
		public array $files = [];
		public function newFile( $name ) {
			return $this->files[$name] ?? false;
		}
	}
	class RepoGroup {
		public function __construct( public LocalRepo $repo ) {
		}
		public function getLocalRepo() {
			return $this->repo;
		}
	}
}
namespace MediaWiki\Extension\VaultTecMediaOptimizer\Storage {
	class OptimizationRecord {
		public array $rows = [];
		public function markSkipped( $n, $r ) {
			$this->rows[$n] = [ 'status' => 'skipped', 'reason' => $r ];
		}
		public function markFailed( $n, $e ) {
			$this->rows[$n] = [ 'status' => 'failed', 'error' => $e ];
		}
		public function markComplete( $n, $o, $opt, $w, $b ) {
			$this->rows[$n] = [ 'status' => 'complete', 'orig' => $o, 'opt' => $opt, 'webp' => $w, 'backend' => $b ];
		}
		public function markPngRecompressed( $n, $s ) {
			$this->rows[$n]['zopfli'] = $s;
		}
	}
}

namespace {
	use MediaWiki\Config\ServiceOptions;
	use MediaWiki\FileRepo\File\File;
	use MediaWiki\FileRepo\LocalRepo;
	use MediaWiki\FileRepo\RepoGroup;
	use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;

	$R = dirname( __DIR__, 2 ) . '/includes';
	require "$R/Service/GifAnimationDetector.php";
	require "$R/Optimizer/OptimizerInterface.php";
	require "$R/Optimizer/AtomicWriteTrait.php";
	require "$R/Optimizer/ImagickOptimizer.php";
	require "$R/Optimizer/GdOptimizer.php";
	require "$R/Optimizer/VipsOptimizer.php";
	require "$R/Optimizer/OptimizerFactory.php";
	require "$R/Storage/WebPRepo.php";
	require "$R/Service/GifOptimizer.php";
	require "$R/Service/ImageProcessor.php";

	$NS = 'MediaWiki\\Extension\\VaultTecMediaOptimizer\\';

	function vtmo_have( string $bin ): bool {
		$out = [];
		$code = 1;
		@exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null', $out, $code );
		return $code === 0;
	}

	$gd = extension_loaded( 'gd' ) ? gd_info() : [];
	if ( empty( $gd['WebP Support'] ) || empty( $gd['AVIF Support'] ) || !function_exists( 'imageavif' )
		|| !vtmo_have( 'convert' ) ) {
		fwrite( STDERR, "SKIP: needs PHP-GD with BOTH WebP and AVIF support, and ImageMagick 'convert'.\n" );
		exit( 0 );
	}

	$logger = new class implements \Psr\Log\LoggerInterface {
		public function debug( $m, array $c = [] ) {
		}
		public function info( $m, array $c = [] ) {
		}
		public function warning( $m, array $c = [] ) {
		}
		public function error( $m, array $c = [] ) {
		}
		public function critical( $m, array $c = [] ) {
		}
		public function alert( $m, array $c = [] ) {
		}
		public function emergency( $m, array $c = [] ) {
		}
		public function notice( $m, array $c = [] ) {
		}
		public function log( $l, $m, array $c = [] ) {
		}
	};

	$pass = 0;
	$fail = 0;
	function check( string $label, bool $cond ): void {
		global $pass, $fail;
		if ( $cond ) {
			$pass++;
			echo "  [OK]  $label\n";
		} else {
			$fail++;
			echo "  [FAIL] $label\n";
		}
	}

	$work = sys_get_temp_dir() . '/vtmo_avif_' . getmypid() . '_' . uniqid();
	$IMG = "$work/images";
	@mkdir( "$IMG/a/aa", 0777, true );
	@mkdir( "$IMG/b/bb", 0777, true );
	register_shutdown_function( static function () use ( $work ) {
		exec( 'rm -rf ' . escapeshellarg( $work ) );
	} );

	// Two contrasting PNGs:
	//  - ui.png   : flat UI -> WebP lossless is tiny; AVIF q50 tends to be larger.
	//  - photo.png: noisy plasma -> WebP lossless is large; AVIF q50 is smaller.
	$uiRel = 'a/aa/Ui.png';
	$phRel = 'b/bb/Photo.png';
	// Very low-entropy UI image: WebP lossless is tiny, so AVIF q50 (with its
	// container overhead) ends up LARGER -> exercises the discard + marker path.
	exec( 'convert -size 256x256 xc:white -fill black -draw "rectangle 40,40 120,120" '
		. escapeshellarg( "$IMG/$uiRel" ) . ' 2>/dev/null' );
	exec( 'convert -size 800x600 plasma:fractal -blur 0x0.5 ' . escapeshellarg( "$IMG/$phRel" ) . ' 2>/dev/null' );
	if ( !is_file( "$IMG/$uiRel" ) || !is_file( "$IMG/$phRel" ) ) {
		fwrite( STDERR, "Could not generate test PNGs.\n" );
		exit( 1 );
	}

	$opts = new ServiceOptions( [
		'VaultTecMediaOptimizerEnabled' => true,
		'VaultTecMediaOptimizerOptimizeOriginals' => false,
		'VaultTecMediaOptimizerFormats' => [ 'image/png', 'image/jpeg', 'image/gif' ],
		'VaultTecMediaOptimizerMaxFileSize' => 52428800,
		'VaultTecMediaOptimizerWebPDirectory' => 'images_webp',
		'VaultTecMediaOptimizerWebPLosslessForPng' => true,
		'VaultTecMediaOptimizerWebPQuality' => 85,
		'VaultTecMediaOptimizerImageEngine' => 'gd',
		'VaultTecMediaOptimizerGifsicleEnabled' => false,
		'VaultTecMediaOptimizerGifsicleBinary' => 'gifsicle',
		'VaultTecMediaOptimizerGifsicleLevel' => 3,
		// AVIF experimental — ON for this test
		'VaultTecMediaOptimizerAvifEnabled' => true,
		'VaultTecMediaOptimizerAvifDirectory' => 'images_avif',
		'VaultTecMediaOptimizerAvifQuality' => 50,
		'UploadDirectory' => $IMG,
		'UploadPath' => '/images',
	] );

	$webpRepo = new ( $NS . 'Storage\\WebPRepo' )( $opts, $logger );
	$factory = new ( $NS . 'Optimizer\\OptimizerFactory' )( $opts, $logger );
	$record = new OptimizationRecord();
	$gifOpt = new ( $NS . 'Service\\GifOptimizer' )( $opts, $logger );
	$repoGroup = new RepoGroup( new LocalRepo() );
	$repoGroup->getLocalRepo()->files['Ui.png'] =
		new File( 'Ui.png', $uiRel, "$IMG/$uiRel", 'image/png', filesize( "$IMG/$uiRel" ) );
	$repoGroup->getLocalRepo()->files['Photo.png'] =
		new File( 'Photo.png', $phRel, "$IMG/$phRel", 'image/png', filesize( "$IMG/$phRel" ) );
	$processor = new ( $NS . 'Service\\ImageProcessor' )(
		$opts, $factory, $webpRepo, $record, $repoGroup, $gifOpt, $logger );

	check( 'GD backend selected', $factory->getOptimizer()->getName() === 'gd' );

	// Independently compute the expected outcome for each image, using the SAME
	// settings the extension uses (PNG -> lossless WebP, AVIF q50), so the test
	// asserts the real decision rather than a hardcoded guess.
	$expectKeepAvif = static function ( string $abs ) use ( $factory ): bool {
		$opt = $factory->getOptimizer();
		$w = $abs . '.cmp.webp';
		$a = $abs . '.cmp.avif';
		$opt->convertToWebP( $abs, $w, true, 85 );
		$opt->convertToAvif( $abs, $a, 50 );
		$ws = is_file( $w ) ? filesize( $w ) : 0;
		$as = is_file( $a ) ? filesize( $a ) : PHP_INT_MAX;
		@unlink( $w );
		@unlink( $a );
		return $as < $ws;
	};

	foreach ( [ 'Ui.png' => $uiRel, 'Photo.png' => $phRel ] as $name => $rel ) {
		$abs = "$IMG/$rel";
		$avifPath = $webpRepo->getAvifPath( $abs );
		$marker = $webpRepo->avifSkipMarker( $avifPath );
		$keep = $expectKeepAvif( $abs );
		echo "\n== $name  (attendu : AVIF " . ( $keep ? 'GARDÉ' : 'REJETÉ' ) . ") ==\n";

		check( "$name : process() = true", $processor->processByName( $name ) === true );
		check( "$name : WebP présent", is_file( $webpRepo->getWebPPath( $abs ) ) );

		if ( $keep ) {
			check( "$name : AVIF gardé (plus petit que le WebP)", is_file( $avifPath ) );
			check( "$name : pas de marqueur .skip", !is_file( $marker ) );
		} else {
			check( "$name : AVIF rejeté (pas sur le disque)", !is_file( $avifPath ) );
			check( "$name : marqueur .skip écrit", is_file( $marker ) );
			check( "$name : avifMarkedSkip() = true", $webpRepo->avifMarkedSkip( $avifPath, $abs ) === true );

			// Second run must NOT regenerate the discarded AVIF (no re-encode loop).
			$mtimeBefore = filemtime( $marker );
			sleep( 1 );
			$processor->processByName( $name );
			clearstatcache();
			check( "$name : 2e passe ne régénère pas l'AVIF", !is_file( $avifPath ) );
			check( "$name : marqueur conservé (pas réécrit)", is_file( $marker ) && filemtime( $marker ) === $mtimeBefore );

			// If the SOURCE changes (newer mtime), the marker is stale -> re-evaluate.
			touch( $abs, time() + 5 );
			check( "$name : source plus récente => marqueur périmé (réévaluation)",
				$webpRepo->avifMarkedSkip( $avifPath, $abs ) === false );
		}
	}

	echo "\nRésultat : $pass passed, $fail failed\n";
	exit( $fail === 0 ? 0 : 1 );
}
