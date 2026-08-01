<?php
/**
 * Animated-GIF / WebP safety verification (GD backend).
 *
 * A GIF -> WebP conversion under PHP-GD can only ever capture the first frame,
 * so emitting such a WebP would silently replace an animation with a still
 * image once it is served. This test proves the extension refuses that:
 *
 *   1. GifAnimationDetector tells animated from still GIFs.
 *   2. GdOptimizer::convertToWebP() refuses an animated GIF (no file written)
 *      but still converts a still GIF normally.
 *   3. End-to-end through the REAL ImageProcessor with the GD backend: an
 *      animated GIF is recorded 'complete' with NO WebP on disk and the
 *      animation preserved; a still GIF whose WebP comes out LARGER than the
 *      GIF has that WebP discarded (never-serve-larger policy, recorded with
 *      webp=0); a photo-like GIF whose WebP is genuinely smaller keeps it.
 *
 * Only MediaWiki/PSR types are stubbed; GD, gifsicle and ImageMagick are real.
 *
 * Requires: PHP-GD with WebP support, ImageMagick `convert`, `gifsicle`.
 * Run:  php tests/integration/gifAnimatedWebp.php
 *
 * @license GPL-2.0-or-later
 */

// ---- Stubbed MediaWiki / PSR types (exact FQCNs) ----
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
	use MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer\GdOptimizer;
	use MediaWiki\Extension\VaultTecMediaOptimizer\Service\GifAnimationDetector;
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

	$gdWebp = extension_loaded( 'gd' ) && !empty( gd_info()['WebP Support'] );
	if ( !$gdWebp || !vtmo_have( 'convert' ) || !vtmo_have( 'gifsicle' ) ) {
		fwrite( STDERR, "SKIP: needs PHP-GD with WebP, ImageMagick 'convert' and 'gifsicle'.\n" );
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

	$work = sys_get_temp_dir() . '/vtmo_animwebp_' . getmypid() . '_' . uniqid();
	mkdir( $work );
	register_shutdown_function( static function () use ( $work ) {
		exec( 'rm -rf ' . escapeshellarg( $work ) );
	} );

	// A 6-frame animated GIF and a single-frame still GIF, both 64x64.
	$anim = "$work/anim.gif";
	$still = "$work/still.gif";
	$mk = 'convert -delay 10 -loop 0 -size 64x64 ';
	for ( $i = 0; $i < 6; $i++ ) {
		$x = 8 + $i * 8;
		$mk .= 'xc:white -fill red -draw ' . escapeshellarg( "circle $x,32 $x,48" ) . ' ';
	}
	exec( $mk . escapeshellarg( $anim ) . ' 2>/dev/null' );
	exec( 'convert -size 64x64 xc:white -fill blue -draw '
		. escapeshellarg( 'rectangle 10,10 50,50' ) . ' ' . escapeshellarg( $still ) . ' 2>/dev/null' );
	// A photo-like GIF (dithered plasma gradient): GIF compresses this poorly,
	// so its lossy WebP is reliably MUCH smaller — exercises the "WebP kept"
	// side of the never-serve-larger policy.
	$photo = "$work/photo.gif";
	exec( 'convert -size 128x128 plasma:fractal ' . escapeshellarg( $photo ) . ' 2>/dev/null' );
	if ( !is_file( $anim ) || !is_file( $still ) || !is_file( $photo ) ) {
		fwrite( STDERR, "Could not generate the test GIFs.\n" );
		exit( 1 );
	}

	echo "== 1. GifAnimationDetector ==\n";
	check( 'animated GIF detected as animated', GifAnimationDetector::isAnimated( $anim ) === true );
	check( 'still GIF detected as NOT animated', GifAnimationDetector::isAnimated( $still ) === false );
	check( 'missing file is not animated (safe default)', GifAnimationDetector::isAnimated( "$work/nope.gif" ) === false );

	echo "\n== 2. GdOptimizer::convertToWebP refusal ==\n";
	$gd = new GdOptimizer(
		new ServiceOptions( [] ),
		$logger
	);
	$animWebp = "$work/anim.webp";
	$stillWebp = "$work/still.webp";

	$okAnim = $gd->convertToWebP( $anim, $animWebp, false, 85 );
	check( 'convertToWebP(animated) returns false', $okAnim === false );
	check( 'no WebP file written for animated GIF', !is_file( $animWebp ) );
	check( 'lastError mentions the animation refusal',
		is_string( $gd->getLastError() ) && stripos( $gd->getLastError(), 'animat' ) !== false );
	check( 'no orphan temp next to refused WebP', ( glob( "$work/anim.webp.vtmo.*" ) ?: [] ) === [] );

	$okStill = $gd->convertToWebP( $still, $stillWebp, false, 85 );
	check( 'convertToWebP(still) returns true', $okStill === true );
	check( 'WebP written for still GIF (RIFF)',
		is_file( $stillWebp ) && substr( (string)file_get_contents( $stillWebp ), 0, 4 ) === 'RIFF' );

	echo "\n== 3. End-to-end via real ImageProcessor (GD backend) ==\n";
	$IMG = "$work/images";
	@mkdir( "$IMG/a/aa", 0777, true );
	@mkdir( "$IMG/b/bb", 0777, true );
	@mkdir( "$IMG/c/cc", 0777, true );
	$animRel = 'a/aa/Anim.gif';
	$stillRel = 'b/bb/Still.gif';
	$photoRel = 'c/cc/Photo.gif';
	copy( $anim, "$IMG/$animRel" );
	copy( $still, "$IMG/$stillRel" );
	copy( $photo, "$IMG/$photoRel" );

	$opts = new ServiceOptions( [
		'VaultTecMediaOptimizerEnabled' => true,
		'VaultTecMediaOptimizerOptimizeOriginals' => true,
		'VaultTecMediaOptimizerFormats' => [ 'image/png', 'image/jpeg', 'image/gif' ],
		'VaultTecMediaOptimizerMaxFileSize' => 52428800,
		'VaultTecMediaOptimizerWebPDirectory' => 'images_webp',
		'VaultTecMediaOptimizerWebPLosslessForPng' => true,
		'VaultTecMediaOptimizerWebPQuality' => 85,
		// Force the GD backend: not 'vips', and no imagick extension in this env.
		'VaultTecMediaOptimizerImageEngine' => 'gd',
		// gifsicle first pass ON, so we also exercise the metadata-refresh path.
		'VaultTecMediaOptimizerGifsicleEnabled' => true,
		'VaultTecMediaOptimizerGifsicleBinary' => 'gifsicle',
		'VaultTecMediaOptimizerGifsicleLevel' => 3,
		'UploadDirectory' => $IMG,
		'UploadPath' => '/images',
	] );

	$webpRepo = new ( $NS . 'Storage\\WebPRepo' )( $opts, $logger );
	$factory = new ( $NS . 'Optimizer\\OptimizerFactory' )( $opts, $logger );
	$record = new OptimizationRecord();
	$gifOpt = new ( $NS . 'Service\\GifOptimizer' )( $opts, $logger );
	$repoGroup = new RepoGroup( new LocalRepo() );
	$repoGroup->getLocalRepo()->files['Anim.gif'] =
		new File( 'Anim.gif', $animRel, "$IMG/$animRel", 'image/gif', filesize( "$IMG/$animRel" ) );
	$repoGroup->getLocalRepo()->files['Still.gif'] =
		new File( 'Still.gif', $stillRel, "$IMG/$stillRel", 'image/gif', filesize( "$IMG/$stillRel" ) );
	$repoGroup->getLocalRepo()->files['Photo.gif'] =
		new File( 'Photo.gif', $photoRel, "$IMG/$photoRel", 'image/gif', filesize( "$IMG/$photoRel" ) );
	$processor = new ( $NS . 'Service\\ImageProcessor' )(
		$opts, $factory, $webpRepo, $record, $repoGroup, $gifOpt, $logger );

	check( 'selected backend is gd', $factory->getOptimizer()->getName() === 'gd' );

	// --- Animated GIF ---
	$animBefore = filesize( "$IMG/$animRel" );
	$okA = $processor->processByName( 'Anim.gif' );
	$animWebpDisk = "$work/images_webp/a/aa/Anim.webp";
	check( 'animated: process() = true (not a failure)', $okA === true );
	check( 'animated: recorded complete', ( $record->rows['Anim.gif']['status'] ?? '' ) === 'complete' );
	check( 'animated: webp size recorded 0', ( $record->rows['Anim.gif']['webp'] ?? -1 ) === 0 );
	check( 'animated: NO WebP file on disk', !is_file( $animWebpDisk ) );
	check( 'animated: original still animated after first pass',
		GifAnimationDetector::isAnimated( "$IMG/$animRel" ) === true );
	check( 'animated: original never grew (keep-if-smaller)', filesize( "$IMG/$animRel" ) <= $animBefore );

	// --- Still GIF (flat colors: its WebP comes out LARGER than the GIF) ---
	// Never-serve-larger policy: the row records success with webp=0 so the
	// original GIF stays the served asset, and the losing WebP is LEFT on disk
	// as a negative cache (deleting it would make the render path re-encode it
	// on every view, burning the on-demand budget for nothing).
	$okS = $processor->processByName( 'Still.gif' );
	$stillWebpDisk = "$work/images_webp/b/bb/Still.webp";
	check( 'still: process() = true', $okS === true );
	check( 'still: recorded complete', ( $record->rows['Still.gif']['status'] ?? '' ) === 'complete' );
	check( 'still (webp larger): recorded as no-WebP (webp=0)',
		( $record->rows['Still.gif']['webp'] ?? -1 ) === 0 );
	check( 'still (webp larger): losing WebP kept on disk as negative cache',
		is_file( $stillWebpDisk ) );
	check( 'still (webp larger): the kept WebP really is >= the original',
		is_file( $stillWebpDisk ) && filesize( $stillWebpDisk ) >= filesize( "$IMG/$stillRel" ) );

	// --- Photo-like GIF (gradients: its WebP is genuinely smaller) ---
	$okP = $processor->processByName( 'Photo.gif' );
	$photoWebpDisk = "$work/images_webp/c/cc/Photo.webp";
	check( 'photo: process() = true', $okP === true );
	check( 'photo: recorded complete', ( $record->rows['Photo.gif']['status'] ?? '' ) === 'complete' );
	check( 'photo (webp smaller): WebP written (RIFF)',
		is_file( $photoWebpDisk ) && substr( (string)file_get_contents( $photoWebpDisk ), 0, 4 ) === 'RIFF' );
	check( 'photo (webp smaller): webp size recorded > 0', ( $record->rows['Photo.gif']['webp'] ?? 0 ) > 0 );
	check( 'photo: served derivative strictly smaller than the original',
		is_file( $photoWebpDisk ) && filesize( $photoWebpDisk ) < filesize( "$IMG/$photoRel" ) );

	echo "\nResult: $pass passed, $fail failed\n";
	exit( $fail === 0 ? 0 : 1 );
}
