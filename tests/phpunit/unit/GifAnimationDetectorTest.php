<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Tests\Unit;

use MediaWiki\Extension\VaultTecMediaOptimizer\Service\GifAnimationDetector;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\VaultTecMediaOptimizer\Service\GifAnimationDetector
 *
 * Regression cover for a real defect: the original implementation grepped for
 * Graphic Control Extension byte patterns and required a 0x00 byte before each
 * one. The first frame's GCE is preceded by the last Global Color Table byte
 * (arbitrary), so animated GIFs without a NETSCAPE loop extension — and legal
 * multi-frame GIFs carrying no GCE at all — were reported as still and got
 * flattened to a one-frame WebP under the GD backend.
 */
class GifAnimationDetectorTest extends MediaWikiUnitTestCase {

	/** @var string[] Files to clean up. */
	private array $tmpFiles = [];

	protected function tearDown(): void {
		foreach ( $this->tmpFiles as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		parent::tearDown();
	}

	private function write( string $bytes ): string {
		$path = tempnam( sys_get_temp_dir(), 'vtmo-gif' );
		file_put_contents( $path, $bytes );
		$this->tmpFiles[] = $path;
		return $path;
	}

	/**
	 * A minimal but structurally valid GIF.
	 *
	 * @param int $frames Number of image descriptors to emit
	 * @param bool $withGce Whether to precede each frame with a GCE
	 * @param string $gct Global Color Table bytes (its last byte is what used
	 *   to break the old byte-pattern heuristic)
	 */
	private function makeGif( int $frames, bool $withGce, string $gct = "\x00\x00\x00\xFF\xFF\xFF" ): string {
		$header = 'GIF89a' . pack( 'v', 2 ) . pack( 'v', 1 ) . "\xF0\x00\x00" . $gct;
		$gce = "\x21\xF9\x04\x00\x0A\x00\x00\x00";
		$descriptor = "\x2C" . pack( 'v', 0 ) . pack( 'v', 0 )
			. pack( 'v', 2 ) . pack( 'v', 1 ) . "\x00";
		$imageData = "\x02\x02\x44\x05\x00";
		$body = '';
		for ( $i = 0; $i < $frames; $i++ ) {
			$body .= ( $withGce ? $gce : '' ) . $descriptor . $imageData;
		}
		return $header . $body . "\x3B";
	}

	public function testStillGifIsNotAnimated() {
		$this->assertFalse(
			GifAnimationDetector::isAnimated( $this->write( $this->makeGif( 1, true ) ) )
		);
	}

	public function testTwoFrameGifIsAnimated() {
		$this->assertTrue(
			GifAnimationDetector::isAnimated( $this->write( $this->makeGif( 2, true ) ) )
		);
	}

	/**
	 * The regression case: multi-frame GIF carrying NO Graphic Control
	 * Extension at all. Legal per the spec, and invisible to the old heuristic.
	 */
	public function testAnimatedGifWithoutAnyGceIsDetected() {
		$this->assertTrue(
			GifAnimationDetector::isAnimated( $this->write( $this->makeGif( 2, false ) ) )
		);
	}

	/**
	 * The other regression case: the byte preceding the first GCE is the last
	 * Global Color Table entry. With a table ending in 0xFF instead of 0x00 the
	 * old pattern missed that frame and under-counted.
	 */
	public function testAnimatedGifIsDetectedWhateverPrecedesTheFirstGce() {
		$gif = $this->makeGif( 2, true, "\x11\x22\x33\xFF\xFF\xFF" );
		$this->assertTrue( GifAnimationDetector::isAnimated( $this->write( $gif ) ) );
	}

	/** @dataProvider provideMalformed */
	public function testMalformedInputNeverThrowsAndReportsStill( string $label, string $bytes ) {
		$this->assertFalse(
			GifAnimationDetector::isAnimated( $this->write( $bytes ) ),
			"malformed input should be reported as still: $label"
		);
	}

	public static function provideMalformed(): array {
		$valid = "GIF89a" . pack( 'v', 2 ) . pack( 'v', 1 ) . "\xF0\x00\x00" . "\x00\x00\x00\xFF\xFF\xFF";
		return [
			'empty file' => [ 'empty', '' ],
			'truncated header' => [ 'truncated', 'GIF89' ],
			'not a GIF' => [ 'png bytes', "\x89PNG\r\n\x1a\n" ],
			'header only' => [ 'header only', $valid ],
			'declared GCT missing' => [ 'gct missing', 'GIF89a' . pack( 'v', 2 ) . pack( 'v', 1 ) . "\xF7\x00\x00" ],
			'unknown block' => [ 'unknown block', $valid . "\x99\x99\x99" ],
			'descriptor without data' => [ 'bare descriptor', $valid . "\x2C" ],
		];
	}

	public function testMissingFileIsNotAnimated() {
		$this->assertFalse( GifAnimationDetector::isAnimated( '/nonexistent/vtmo/none.gif' ) );
	}

	/**
	 * A pathological run of extension blocks must terminate rather than spin.
	 */
	public function testLongExtensionChainTerminates() {
		$valid = 'GIF89a' . pack( 'v', 2 ) . pack( 'v', 1 ) . "\xF0\x00\x00" . "\x00\x00\x00\xFF\xFF\xFF";
		$path = $this->write( $valid . str_repeat( "\x21\xF9\x00", 5000 ) );
		$start = microtime( true );
		$this->assertFalse( GifAnimationDetector::isAnimated( $path ) );
		$this->assertLessThan( 5, microtime( true ) - $start, 'parser must not hang' );
	}
}
