<?php
/**
 * Authentic GIF-optimization verification.
 *
 * Drives the REAL GifOptimizer class against the REAL `gifsicle` binary and
 * proves the optimization is lossless and animation-safe. Only the two
 * MediaWiki/PSR types GifOptimizer depends on are stubbed.
 *
 * Requires: gifsicle and ImageMagick (`convert`) on PATH. The test generates
 * its own animated GIF, so it has no fixtures.
 *
 * Run:
 *   php tests/integration/gif.php
 *
 * @license GPL-2.0-or-later
 */

// --- Minimal stubs for the dependencies (real MediaWiki not loaded here) ---

namespace MediaWiki\Config {
	class ServiceOptions {
		private array $vals;
		public function __construct( array $vals ) {
			$this->vals = $vals;
		}
		public function get( $k ) {
			return $this->vals[$k] ?? null;
		}
	}
}

namespace Psr\Log {
	if ( !interface_exists( \Psr\Log\LoggerInterface::class ) ) {
		interface LoggerInterface {
			public function warning( $m, array $c = [] );
			public function debug( $m, array $c = [] );
			public function info( $m, array $c = [] );
			public function error( $m, array $c = [] );
		}
	}
	class VtmoNullLogger implements LoggerInterface {
		public function warning( $m, array $c = [] ) {
		}
		public function debug( $m, array $c = [] ) {
		}
		public function info( $m, array $c = [] ) {
		}
		public function error( $m, array $c = [] ) {
		}
	}
}

namespace {
	require __DIR__ . '/../../includes/Service/GifOptimizer.php';

	use MediaWiki\Config\ServiceOptions;
	use MediaWiki\Extension\VaultTecMediaOptimizer\Service\GifOptimizer;
	use Psr\Log\VtmoNullLogger;

	function vtmo_have( string $bin ): bool {
		$out = [];
		$code = 1;
		@exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null', $out, $code );
		return $code === 0;
	}

	if ( !vtmo_have( 'gifsicle' ) || !vtmo_have( 'convert' ) ) {
		fwrite( STDERR, "SKIP: this test needs both 'gifsicle' and ImageMagick 'convert' on PATH.\n" );
		exit( 0 );
	}

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

	$work = sys_get_temp_dir() . '/vtmo_gif_' . getmypid() . '_' . uniqid();
	mkdir( $work );
	register_shutdown_function( static function () use ( $work ) {
		array_map( 'unlink', glob( $work . '/*' ) ?: [] );
		@rmdir( $work );
	} );

	// Generate a 12-frame animated GIF (320x200), deliberately un-optimized so
	// there is real structural redundancy for gifsicle to remove.
	$gif = $work . '/anim.gif';
	$mk = 'convert -delay 8 -loop 0 -size 320x200 ';
	for ( $i = 0; $i < 12; $i++ ) {
		$x = 20 + $i * 20;
		$mk .= 'xc:white -fill blue -draw ' . escapeshellarg( "circle $x,100 $x,140" ) . ' ';
	}
	$mk .= escapeshellarg( $gif );
	exec( $mk . ' 2>/dev/null' );
	if ( !is_file( $gif ) || filesize( $gif ) === 0 ) {
		fwrite( STDERR, "Could not generate the test GIF with ImageMagick.\n" );
		exit( 1 );
	}

	// Render signature: coalesce to full frames, then hash the CANONICAL raw
	// RGBA pixel bytes of each frame. We deliberately do NOT hash the PNG files
	// directly — two pixel-identical frames can still produce different PNG
	// encodings (palette vs truecolor, tRNS chunk ordering) after gifsicle
	// re-packs the GIF. Raw RGBA has no such encoding variance, so an identical
	// signature proves the rendered animation is byte-identical pixel-wise.
	$frameSig = static function ( string $g ) use ( $work ): array {
		$dir = $work . '/sig_' . uniqid();
		mkdir( $dir );
		exec( 'convert ' . escapeshellarg( $g ) . ' -coalesce '
			. escapeshellarg( $dir . '/f_%03d.png' ) . ' 2>/dev/null' );
		$files = glob( $dir . '/f_*.png' ) ?: [];
		sort( $files );
		$sig = [];
		foreach ( $files as $f ) {
			$raw = $f . '.rgba';
			exec( 'convert ' . escapeshellarg( $f ) . ' -depth 8 '
				. escapeshellarg( 'RGBA:' . $raw ) . ' 2>/dev/null' );
			$sig[] = is_file( $raw ) ? md5_file( $raw ) : 'noconv';
		}
		array_map( 'unlink', glob( $dir . '/*' ) ?: [] );
		@rmdir( $dir );
		return $sig;
	};

	$gifInfo = static function ( string $g ): array {
		$out = [];
		exec( 'gifsicle --info ' . escapeshellarg( $g ) . ' 2>/dev/null', $out );
		$txt = implode( "\n", $out );
		preg_match( '/logical screen (\d+)x(\d+)/', $txt, $d );
		return [
			'frames' => preg_match_all( '/^\s*\+ image #/m', $txt ),
			'w' => $d[1] ?? '?',
			'h' => $d[2] ?? '?',
			'loop' => strpos( $txt, 'loop forever' ) !== false,
		];
	};

	$make = static function ( bool $enabled = true, string $bin = 'gifsicle', int $level = 3 ): GifOptimizer {
		return new GifOptimizer(
			new ServiceOptions( [
				'VaultTecMediaOptimizerGifsicleEnabled' => $enabled,
				'VaultTecMediaOptimizerGifsicleBinary' => $bin,
				'VaultTecMediaOptimizerGifsicleLevel' => $level,
			] ),
			new VtmoNullLogger()
		);
	};

	echo "== GifOptimizer real-binary verification ==\n";

	// Availability gating.
	check( 'isAvailable() true when enabled + binary present', $make()->isAvailable() === true );
	check( 'isAvailable() false when feature disabled', $make( false )->isAvailable() === false );
	check( 'isAvailable() false for missing binary', $make( true, 'no-such-binary-xyz' )->isAvailable() === false );

	// Disabled is a harmless no-op that leaves the file untouched.
	$off = $work . '/off.gif';
	copy( $gif, $off );
	$beforeOff = filesize( $off );
	check( 'optimize() returns null when disabled', $make( false )->optimize( $off ) === null );
	check( 'file untouched when disabled', filesize( $off ) === $beforeOff );

	// Real optimization: smaller, lossless, animation-safe.
	$g = $make();
	$file = $work . '/run.gif';
	copy( $gif, $file );
	$infoB = $gifInfo( $file );
	$sigB = $frameSig( $file );
	$orig = filesize( $file );

	$saved = $g->optimize( $file );
	clearstatcache();
	$new = filesize( $file );
	$infoA = $gifInfo( $file );
	$sigA = $frameSig( $file );

	echo "  size: $orig -> $new (saved " . var_export( $saved, true ) . ")\n";
	check( 'optimize() returned bytes saved (positive int)', is_int( $saved ) && $saved > 0 );
	check( 'file got strictly smaller', $new < $orig );
	check( 'saved == orig - new', $saved === ( $orig - $new ) );
	check( 'width preserved (no resize)', $infoB['w'] === $infoA['w'] );
	check( 'height preserved (no resize)', $infoB['h'] === $infoA['h'] );
	check( 'frame count preserved', $infoB['frames'] === $infoA['frames'] && $infoB['frames'] > 1 );
	check( 'loop flag preserved', $infoB['loop'] === $infoA['loop'] );
	check( 'rendered frames pixel-identical (LOSSLESS)', $sigB === $sigA && count( $sigB ) > 1 );

	// Idempotence + keep-if-smaller.
	$sizeBefore2 = filesize( $file );
	$saved2 = $g->optimize( $file );
	clearstatcache();
	check( 'second pass returns int', is_int( $saved2 ) );
	check( 'second pass never grows the file', filesize( $file ) <= $sizeBefore2 );
	check( 'second pass still lossless', $frameSig( $file ) === $sigA );

	// No orphan temp files.
	check( 'no leftover .gifsicle temp files', ( glob( $work . '/*.gifsicle.*.tmp' ) ?: [] ) === [] );

	echo "\nResult: $pass passed, $fail failed\n";
	exit( $fail === 0 ? 0 : 1 );
}
