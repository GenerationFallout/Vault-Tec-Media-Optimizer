<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Tests\Unit;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer\OptimizerFactory;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\HtmlRewriter;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @covers \MediaWiki\Extension\VaultTecMediaOptimizer\Service\HtmlRewriter
 *
 * Covers the pure-logic helpers of the rewriter: <picture> range detection
 * (idempotency) and local-URL recognition. Both had defects found by audit —
 * the range detection used to be a fixed 500-byte look-back that could
 * double-wrap an <img> whose wrapper was longer than the window.
 */
class HtmlRewriterTest extends MediaWikiUnitTestCase {

	private function newRewriter(): HtmlRewriter {
		$options = new ServiceOptions(
			[
				'UploadDirectory',
				'UploadPath',
				'VaultTecMediaOptimizerWebPDirectory',
			],
			[
				'UploadDirectory' => '/var/www/wiki/images',
				'UploadPath' => '/images',
				'VaultTecMediaOptimizerWebPDirectory' => 'images_webp',
			]
		);
		return new HtmlRewriter(
			$options,
			new WebPRepo( $options, new NullLogger() ),
			$this->createMock( OptimizerFactory::class ),
			new NullLogger()
		);
	}

	/** @dataProvider provideLocalSrc */
	public function testSrcLooksLocal( string $label, string $src, bool $expected ) {
		$this->assertSame(
			$expected,
			$this->newRewriter()->srcLooksLocal( $src, '/images' ),
			$label
		);
	}

	public static function provideLocalSrc(): array {
		return [
			[ 'relative upload path', '/images/a/ab/File.png', true ],
			[ 'thumbnail', '/images/thumb/a/ab/File.png/220px-File.png', true ],
			[ 'thumb.php entry point', '/thumb.php?f=File.png&width=220', true ],
			[ 'absolute URL on this wiki', 'https://wiki.example/images/a/ab/File.png', true ],
			[ 'foreign host', 'https://commons.example/other/File.png', false ],
			[ 'data URI', 'data:image/png;base64,AAAA', false ],
			[ 'unrelated path', '/w/skins/logo.png', false ],
		];
	}

	/**
	 * Idempotency: an <img> already inside a <picture> must never be wrapped
	 * again, however long the wrapper is. The old fixed-window look-back
	 * missed wrappers whose <source> pushed the inner <img> out of range.
	 */
	public function testImgInsideAVeryLongPictureIsDetected() {
		$longSrcset = str_repeat( '/images_webp/a/ab/File.webp 2x, ', 60 );
		$html = '<p>x</p><picture><source srcset="' . $longSrcset . '" type="image/webp">'
			. '<img src="/images/a/ab/File.png"></picture>';
		$imgOffset = strpos( $html, '<img' );

		$this->assertTrue(
			$this->newRewriter()->isInsidePicture( $html, $imgOffset ),
			'an <img> after a long <source> is still inside the <picture>'
		);
	}

	public function testImgOutsideAnyPictureIsNotDetected() {
		$html = '<picture><source srcset="/images_webp/a/ab/A.webp"><img src="/images/a/ab/A.png">'
			. '</picture><img src="/images/b/bb/B.png">';
		$secondImg = strrpos( $html, '<img' );

		$this->assertFalse( $this->newRewriter()->isInsidePicture( $html, $secondImg ) );
	}

	public function testOffsetsAcrossSeveralPictureBlocks() {
		$html = '<picture><img src="/images/a/A.png"></picture>'
			. '<img src="/images/b/B.png">'
			. '<picture><img src="/images/c/C.png"></picture>';
		$rewriter = $this->newRewriter();

		$first = strpos( $html, '<img' );
		$second = strpos( $html, '<img', $first + 1 );
		$third = strrpos( $html, '<img' );

		$this->assertTrue( $rewriter->isInsidePicture( $html, $first ) );
		$this->assertFalse( $rewriter->isInsidePicture( $html, $second ) );
		$this->assertTrue( $rewriter->isInsidePicture( $html, $third ) );
	}

	public function testHtmlWithoutAnyPictureIsCheap() {
		$html = '<p>no images here</p>';
		$this->assertFalse( $this->newRewriter()->isInsidePicture( $html, 3 ) );
	}
}
