<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Tests\Unit;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @covers \MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo
 *
 * Path resolution is where several real defects lived, so every case below is
 * a regression guard rather than a happy-path smoke test.
 */
class WebPRepoTest extends MediaWikiUnitTestCase {

	private function newRepo( array $overrides = [] ): WebPRepo {
		return new WebPRepo(
			new ServiceOptions(
				[
					'UploadDirectory',
					'UploadPath',
					'VaultTecMediaOptimizerWebPDirectory',
				],
				$overrides + [
					'UploadDirectory' => '/var/www/wiki/images',
					'UploadPath' => '/images',
					'VaultTecMediaOptimizerWebPDirectory' => 'images_webp',
				]
			),
			new NullLogger()
		);
	}

	/**
	 * The derived tree's directory name is the ONLY thing separating files we
	 * freely create and recursively purge from MediaWiki's real upload tree.
	 * A value equal to the upload directory's basename used to make the
	 * reupload purge delete MediaWiki's actual thumbnails.
	 *
	 * @dataProvider provideInvalidDirNames
	 */
	public function testInvalidWebPDirectoryFallsBackToASafeDefault( string $label, string $value ) {
		$root = $this->newRepo( [ 'VaultTecMediaOptimizerWebPDirectory' => $value ] )->getRootDir();
		$this->assertSame( '/var/www/wiki/images_webp', $root, "rejected value: $label" );
	}

	public static function provideInvalidDirNames(): array {
		return [
			'collides with the upload dir' => [ 'collision', 'images' ],
			'empty' => [ 'empty', '' ],
			'current dir' => [ 'dot', '.' ],
			'parent dir' => [ 'dotdot', '..' ],
			'contains a separator' => [ 'traversal', '../outside' ],
			'absolute path' => [ 'absolute', '/etc' ],
		];
	}

	public function testValidWebPDirectoryIsHonoured() {
		$repo = $this->newRepo( [ 'VaultTecMediaOptimizerWebPDirectory' => 'derived_webp' ] );
		$this->assertSame( '/var/www/wiki/derived_webp', $repo->getRootDir() );
		$this->assertSame( '/derived_webp', $repo->getRootUrl() );
	}

	/**
	 * A stripped query string used to be counted in the origin-prefix length
	 * arithmetic, corrupting the emitted URL (e.g. "/ima/images_webp/...").
	 *
	 * @dataProvider provideUrls
	 */
	public function testWebPUrlIsBuiltCorrectly( string $input, string $expected ) {
		$info = $this->newRepo()->getWebPUrlAndPath( $input );
		$this->assertIsArray( $info );
		$this->assertSame( $expected, $info[0] );
	}

	public static function provideUrls(): array {
		return [
			'relative path' => [
				'/images/a/ab/File.png',
				'/images_webp/a/ab/File.webp',
			],
			'relative path with a query string' => [
				'/images/a/ab/File.png?x=1',
				'/images_webp/a/ab/File.webp',
			],
			'absolute URL' => [
				'https://wiki.example/images/a/ab/File.png',
				'https://wiki.example/images_webp/a/ab/File.webp',
			],
			'absolute URL with a query string' => [
				'https://wiki.example/images/a/ab/File.png?x=1',
				'https://wiki.example/images_webp/a/ab/File.webp',
			],
			'thumbnail' => [
				'/images/thumb/a/ab/File.png/220px-File.png',
				'/images_webp/thumb/a/ab/File.png/220px-File.webp',
			],
		];
	}

	public function testUrlOutsideTheUploadAreaIsRejected() {
		$this->assertNull( $this->newRepo()->getWebPUrlAndPath( '/other/a/ab/File.png' ) );
	}

	/**
	 * The traversal guard used to test for ".." as a SUBSTRING, so a title
	 * MediaWiki legitimately allows — "v..2.png" — was denied any derivative.
	 */
	public function testFilenameContainingDotDotIsAccepted() {
		$info = $this->newRepo()->getWebPUrlAndPath( '/images/2/24/v..2.png' );
		$this->assertIsArray( $info );
		$this->assertSame( '/images_webp/2/24/v..2.webp', $info[0] );
	}

	public function testRealTraversalSegmentIsStillRejected() {
		$this->assertNull(
			$this->newRepo()->getWebPUrlAndPath( '/images/a/../../etc/passwd' )
		);
	}

	/**
	 * ".webp" is one byte longer than ".png", so a name MediaWiki accepts can
	 * tip the derived basename past NAME_MAX purely by being converted. The
	 * write then fails every time, is never negatively cached, and burns a
	 * unit of the per-render budget on every single page view.
	 */
	public function testPathLengthGuard() {
		$repo = $this->newRepo();
		$this->assertTrue(
			$repo->isWritablePathLength( '/var/www/wiki/images_webp/a/ab/Short.webp' )
		);
		$this->assertFalse(
			$repo->isWritablePathLength(
				'/var/www/wiki/images_webp/a/ab/' . str_repeat( 'x', 240 ) . '.webp'
			)
		);
	}

	public function testReplaceExtension() {
		$repo = $this->newRepo();
		$this->assertSame( 'a/b/File.webp', $repo->replaceExtension( 'a/b/File.png', 'webp' ) );
		$this->assertSame( 'a/b/File.webp', $repo->replaceExtension( 'a/b/File.jpeg', 'webp' ) );
		$this->assertSame( 'noext.webp', $repo->replaceExtension( 'noext', 'webp' ) );
	}
}
