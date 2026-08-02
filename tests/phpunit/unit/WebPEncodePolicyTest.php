<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Tests\Unit;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer\OptimizerInterface;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\WebPEncodePolicy;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @covers \MediaWiki\Extension\VaultTecMediaOptimizer\Service\WebPEncodePolicy
 */
class WebPEncodePolicyTest extends MediaWikiUnitTestCase {

	/** @var string[] */
	private array $tmp = [];

	protected function tearDown(): void {
		foreach ( $this->tmp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		parent::tearDown();
	}

	/**
	 * @param bool|string $pngMode
	 * @param int $quality
	 * @return WebPEncodePolicy
	 */
	private function newPolicy( $pngMode, int $quality = 85 ): WebPEncodePolicy {
		return new WebPEncodePolicy(
			new ServiceOptions(
				[ 'VaultTecMediaOptimizerWebPLosslessForPng', 'VaultTecMediaOptimizerWebPQuality' ],
				[
					'VaultTecMediaOptimizerWebPLosslessForPng' => $pngMode,
					'VaultTecMediaOptimizerWebPQuality' => $quality,
				]
			),
			new NullLogger()
		);
	}

	private function tmpPath(): string {
		$p = tempnam( sys_get_temp_dir(), 'vtmo-pol' );
		unlink( $p );
		$this->tmp[] = $p;
		$this->tmp[] = $p . '.lossy';
		return $p;
	}

	/**
	 * A fake optimizer that writes files of caller-chosen sizes so the policy's
	 * decision can be observed without any real encoder.
	 */
	private function fakeOptimizer( int $losslessBytes, int $lossyBytes ): OptimizerInterface {
		$opt = $this->createMock( OptimizerInterface::class );
		$opt->method( 'convertToWebP' )->willReturnCallback(
			static function ( $src, $dest, $lossless, $quality )
				use ( $losslessBytes, $lossyBytes ) {
				file_put_contents( $dest, str_repeat( 'x', $lossless ? $losslessBytes : $lossyBytes ) );
				return true;
			}
		);
		return $opt;
	}

	public function testQualityIsClampedToLibwebpRange() {
		$this->assertSame( 100, $this->newPolicy( true, 150 )->quality() );
		$this->assertSame( 0, $this->newPolicy( true, -5 )->quality() );
		$this->assertSame( 0, $this->newPolicy( true, 0 )->quality(), 'non-numeric config casts to 0' );
		$this->assertSame( 85, $this->newPolicy( true, 85 )->quality() );
	}

	public function testLosslessOnlyAppliesToPng() {
		$policy = $this->newPolicy( true );
		$this->assertTrue( $policy->isLossless( 'image/png' ) );
		$this->assertFalse( $policy->isLossless( 'image/jpeg' ) );
		$this->assertFalse( $policy->isLossless( 'image/gif' ) );
	}

	public function testAutoModeIsNotReportedAsFixedLossless() {
		$this->assertFalse( $this->newPolicy( 'auto' )->isLossless( 'image/png' ) );
	}

	/**
	 * The point of 'auto': on photographic PNGs the lossy encode was measured
	 * 42% smaller than the lossless one, which the old boolean config could
	 * never pick.
	 */
	public function testAutoKeepsTheLossyEncodeWhenItIsSmaller() {
		$dest = $this->tmpPath();
		$ok = $this->newPolicy( 'auto' )->encodeBest(
			$this->fakeOptimizer( 1000, 400 ), '/src.png', $dest, 'image/png'
		);

		$this->assertTrue( $ok );
		$this->assertSame( 400, filesize( $dest ), 'the smaller (lossy) encode wins' );
		$this->assertFileDoesNotExist( $dest . '.lossy', 'the losing candidate is cleaned up' );
	}

	public function testAutoKeepsTheLosslessEncodeWhenItIsSmaller() {
		$dest = $this->tmpPath();
		$ok = $this->newPolicy( 'auto' )->encodeBest(
			$this->fakeOptimizer( 300, 900 ), '/src.png', $dest, 'image/png'
		);

		$this->assertTrue( $ok );
		$this->assertSame( 300, filesize( $dest ) );
		$this->assertFileDoesNotExist( $dest . '.lossy' );
	}

	public function testFixedLosslessModeEncodesOnce() {
		$dest = $this->tmpPath();
		$opt = $this->createMock( OptimizerInterface::class );
		$opt->expects( $this->once() )
			->method( 'convertToWebP' )
			->with( '/src.png', $dest, true, 85 )
			->willReturnCallback( static function ( $s, $d ) {
				file_put_contents( $d, 'x' );
				return true;
			} );

		$this->assertTrue(
			$this->newPolicy( true )->encodeBest( $opt, '/src.png', $dest, 'image/png' )
		);
	}

	/**
	 * 'auto' is a PNG-only policy: a JPEG must still take the single lossy
	 * encode path, never the double-encode comparison.
	 */
	public function testNonPngIgnoresAutoAndEncodesOnce() {
		$dest = $this->tmpPath();
		$opt = $this->createMock( OptimizerInterface::class );
		$opt->expects( $this->once() )
			->method( 'convertToWebP' )
			->with( '/src.jpg', $dest, false, 85 )
			->willReturnCallback( static function ( $s, $d ) {
				file_put_contents( $d, 'x' );
				return true;
			} );

		$this->assertTrue(
			$this->newPolicy( 'auto' )->encodeBest( $opt, '/src.jpg', $dest, 'image/jpeg' )
		);
	}

	public function testEncodeFailureIsReported() {
		$dest = $this->tmpPath();
		$opt = $this->createMock( OptimizerInterface::class );
		$opt->method( 'convertToWebP' )->willReturn( false );

		$this->assertFalse(
			$this->newPolicy( true )->encodeBest( $opt, '/src.png', $dest, 'image/png' )
		);
	}
}
