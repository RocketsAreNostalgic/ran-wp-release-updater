<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\WordPress\InstalledPackageResolver;

final class InstalledPackageResolverTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2) . '/.workspaces/p0.1/installed-resolver-' . bin2hex(random_bytes(6));
		mkdir($this->root . '/plugins', 0700, true);
		mkdir($this->root . '/themes', 0700, true);
		$GLOBALS['wp_version'] = '6.8.0';
	}

	protected function tearDown(): void { $this->remove($this->root); }

	public function testPluginRootsMappingsAndPassiveFileCodes(): void
	{
		$plugin = $this->file('plugins/renamed/main.php', $this->pluginHeader());
		$resolver = $this->resolver();
		self::assertSame('renamed/main.php', $resolver->resolve($this->declaration('plugin', $plugin))['installed_package_identity']); // P01/P02.
		self::assertSame('plugin_root_level_unsupported', $resolver->resolve($this->declaration('plugin', $this->file('plugins/root.php', $this->pluginHeader())))['code']); // P03.
		self::assertSame('installed_file_missing', $resolver->resolve($this->declaration('plugin', $this->root . '/plugins/missing.php'))['code']);
		self::assertSame('installed_file_not_regular', $resolver->resolve($this->declaration('plugin', $this->root . '/plugins/renamed'))['code']); // P04.
		self::assertSame('installed_file_not_regular', $resolver->resolve($this->declaration('plugin', '/dev/null'))['code']); // P04 device.
		symlink($plugin, $this->root . '/plugins/link.php');
		self::assertSame('installed_file_symlink', $resolver->resolve($this->declaration('plugin', $this->root . '/plugins/link.php'))['code']); // P05.
		self::assertSame('installed_file_outside_root', $resolver->resolve($this->declaration('plugin', $this->file('outside/x.php', $this->pluginHeader())))['code']); // P07.
	}

	public function testRegisteredAncestorSymlinkEquivalentMapsAndAmbiguousMaps(): void
	{
		$actual = $this->root . '/actual/slug';
		mkdir($actual, 0700, true);
		file_put_contents($actual . '/main.php', $this->pluginHeader());
		$logical = $this->root . '/plugins/slug';
		symlink($actual, $logical);
		$mapped = new InstalledPackageResolver($this->root . '/plugins', array($logical => $actual), array());
		self::assertSame('slug/main.php', $mapped->resolve($this->declaration('plugin', $logical . '/main.php'))['installed_package_identity']);
		self::assertSame('slug/main.php', $mapped->resolve($this->declaration('plugin', $actual . '/main.php'))['installed_package_identity']); // P06.
		$ambiguous = new InstalledPackageResolver($this->root . '/plugins', array($logical => $actual, $this->root . '/plugins/other' => $actual), array());
		self::assertSame('installed_file_root_ambiguous', $ambiguous->resolve($this->declaration('plugin', $actual . '/main.php'))['code']); // P08.
	}

	public function testSymlinkedRegisteredRootsAndUnregisteredInternalLinks(): void
	{
		$actualPlugins = $this->root . '/actual-plugins';
		mkdir($actualPlugins . '/slug', 0700, true);
		file_put_contents($actualPlugins . '/slug/main.php', $this->pluginHeader());
		$logicalPlugins = $this->root . '/logical-plugins';
		symlink($actualPlugins, $logicalPlugins);
		$plugins = new InstalledPackageResolver($logicalPlugins, array(), array());
		self::assertSame('slug/main.php', $plugins->resolve($this->declaration('plugin', $logicalPlugins . '/slug/main.php'))['installed_package_identity']);
		self::assertSame('slug/main.php', $plugins->resolve($this->declaration('plugin', $actualPlugins . '/slug/main.php'))['installed_package_identity']);
		$actualThemes = $this->root . '/actual-themes';
		mkdir($actualThemes . '/slug', 0700, true);
		file_put_contents($actualThemes . '/slug/style.css', $this->themeHeader());
		$logicalThemes = $this->root . '/logical-themes';
		symlink($actualThemes, $logicalThemes);
		$themes = new InstalledPackageResolver('', array(), array($logicalThemes));
		self::assertSame('slug', $themes->resolve($this->declaration('theme', $logicalThemes . '/slug/style.css'))['installed_package_identity']);
		self::assertSame('slug', $themes->resolve($this->declaration('theme', $actualThemes . '/slug/style.css'))['installed_package_identity']);
		$external = $this->root . '/external'; mkdir($external . '/plugin', 0700, true); file_put_contents($external . '/plugin/main.php', $this->pluginHeader());
		symlink($external . '/plugin', $this->root . '/plugins/inside');
		self::assertSame('installed_file_outside_root', $this->resolver()->resolve($this->declaration('plugin', $this->root . '/plugins/inside/main.php'))['code']);
		mkdir($external . '/theme', 0700, true); file_put_contents($external . '/theme/style.css', $this->themeHeader());
		symlink($external . '/theme', $this->root . '/themes/inside');
		self::assertSame('installed_file_outside_root', $this->resolver()->resolve($this->declaration('theme', $this->root . '/themes/inside/style.css'))['code']);
	}

	public function testParserUsesBoundedNormalizedHeaderValuesOnly(): void
	{
		$header = "<?php /* Plugin Name: caf\xc3\xa9 */ trailing\rVersion: 1.0.0\rUpdate URI: https://github.com/acme/example\r";
		$result = PackageIdentityValidator::parseHeader($header . "\x00", 'plugin');
		self::assertSame('installed_header_verified', $result['code']);
		self::assertSame('café', $result['headers']['Name']);
		self::assertSame('installed_header_ambiguous', PackageIdentityValidator::parseHeader($this->pluginHeader() . "<?php /* Version: 1.0.0 */\n", 'plugin')['code']);
		self::assertSame('installed_header_missing', PackageIdentityValidator::parseHeader("<?php /* Plugin Name: Example */\n", 'plugin')['code']);
		self::assertSame('installed_header_invalid', PackageIdentityValidator::parseHeader("<?php /* Plugin Name: bad\x00 */\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n", 'plugin')['code']);
		self::assertSame('installed_header_missing', PackageIdentityValidator::parseHeader(str_repeat('x', 8192) . "\nPlugin Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example", 'plugin')['code']);
	}

	public function testPathTraversalAndLaterHeaderColonAreRejectedOrParsedExactly(): void
	{
		$resolver = $this->resolver();
		foreach ( array( 'acme/.', 'acme/..', 'acme\\', 'acme\\.\\main.php', 'acme\\..\\main.php' ) as $suffix ) {
			self::assertSame( 'installed_file_invalid', $resolver->resolve( $this->declaration( 'plugin', $this->root . '/plugins/' . $suffix ) )['code'], $suffix );
		}
		$header = "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\nSomething else: value\nUpdate URI: https://github.com/acme/example\n*/\n";
		self::assertSame( 'installed_header_verified', PackageIdentityValidator::parseHeader( $header, 'plugin' )['code'] );
		$splitColon = "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\nUpdate URI\n: https://github.com/acme/example\n*/\n";
		self::assertSame( 'installed_header_missing', PackageIdentityValidator::parseHeader( $splitColon, 'plugin' )['code'] );
	}

	public function testThemeIdentityChildParentNestedAndSymlinkBoundaries(): void
	{
		$child = $this->file('themes/child/style.css', $this->themeHeader('Template: parent'));
		$parent = $this->file('themes/parent/style.css', $this->themeHeader());
		$resolver = $this->resolver();
		self::assertSame('child', $resolver->resolve($this->declaration('theme', $child))['installed_package_identity']); // T01/T03.
		self::assertSame('parent', $resolver->resolve($this->declaration('theme', $parent))['installed_package_identity']); // T04.
		self::assertSame('theme_nested_identity_unsupported', $resolver->resolve($this->declaration('theme', $this->file('themes/group/theme/style.css', $this->themeHeader())))['code']); // T05.
		self::assertSame('theme_header_file_invalid', $resolver->resolve($this->declaration('theme', $this->file('themes/bad/theme.css', $this->themeHeader())))['code']); // T06.
		$custom = $this->root . '/custom-themes'; mkdir($custom . '/custom', 0700, true); file_put_contents($custom . '/custom/style.css', $this->themeHeader());
		self::assertSame('custom', (new InstalledPackageResolver('', array(), array($custom)))->resolve($this->declaration('theme', $custom . '/custom/style.css'))['installed_package_identity']); // T02.
	}

	public function testHeaderNormalizationDuplicatesRequirementsAndReadMutation(): void
	{
		$lf = $this->file('plugins/lf/main.php', $this->pluginHeader("\n"));
		$crlf = $this->file('plugins/crlf/main.php', str_replace("\n", "\r\n", $this->pluginHeader("\n")));
		$cr = $this->file('plugins/cr/main.php', str_replace("\n", "\r", $this->pluginHeader("\n")));
		$resolver = $this->resolver();
		self::assertSame($resolver->resolve($this->declaration('plugin', $lf))['headers'], $resolver->resolve($this->declaration('plugin', $crlf))['headers']); // H01.
		self::assertSame($resolver->resolve($this->declaration('plugin', $lf))['headers'], $resolver->resolve($this->declaration('plugin', $cr))['headers']); // H01 lone CR.
		self::assertSame('installed_header_ambiguous', $resolver->resolve($this->declaration('plugin', $this->file('plugins/duplicate/main.php', $this->pluginHeader() . "Plugin Name: Again\n")))['code']); // H02.
		self::assertSame('installed_header_missing', $resolver->resolve($this->declaration('plugin', $this->file('plugins/missing/main.php', "<?php\n/* Version: 1.0.0 */")))['code']); // H03.
		self::assertSame('installed_header_invalid', $resolver->resolve($this->declaration('plugin', $this->file('plugins/invalid-version/main.php', "<?php\n/*\nPlugin Name: Example\nVersion: broken\nUpdate URI: https://github.com/acme/example\n*/\n")))['code']);
		self::assertSame('installed_header_verified', PackageIdentityValidator::parseHeader($this->pluginHeader() . "\x00", 'plugin')['code']);
		self::assertSame('installed_header_invalid', PackageIdentityValidator::parseHeader($this->themeHeader('Template: ../parent'), 'theme')['code']);
		self::assertSame('installed_requirement_incompatible', $resolver->resolve($this->declaration('plugin', $this->file('plugins/requirements/main.php', $this->pluginHeader("\nRequires PHP: 99.0\n"))))['code']);
		$changed = $this->file('plugins/changed/main.php', $this->pluginHeader());
		$property = new \ReflectionProperty(InstalledPackageResolver::class, 'afterFirstRead');
		$property->setValue($resolver, static function (string $file): void { file_put_contents($file, "<?php\n/* Plugin Name: Changed\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n"); });
		self::assertSame('installed_file_changed', $resolver->resolve($this->declaration('plugin', $changed))['code']); // R01.
		$initialDrift = $this->file('plugins/initial-drift/main.php', $this->pluginHeader());
		$before = new \ReflectionProperty(InstalledPackageResolver::class, 'beforeFirstStat');
		$before->setValue($resolver, static function (string $file): void { rename($file, $file . '.old'); file_put_contents($file, "<?php\n/* Plugin Name: Replacement\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n"); });
		self::assertSame('installed_file_changed', $resolver->resolve($this->declaration('plugin', $initialDrift))['code']);
	}

	public function testInstalledHeadersPermitAbsentRequirementsAndRejectPostClosingDuplicates(): void
	{
		$resolver = $this->resolver();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$relative = 'plugin' === $type ? 'plugins/optional/main.php' : 'themes/optional/style.css';
			$header = 'plugin' === $type ? $this->pluginHeader() : $this->themeHeader();
			$file = $this->file( $relative, $header );
			self::assertSame( 'installed_identity_verified', $resolver->resolve( $this->declaration( $type, $file ) )['code'] );

			$file = $this->file( str_replace( 'optional', 'duplicate', $relative ), $header . "Version: 1.0.0\n" );
			self::assertSame( 'installed_header_ambiguous', $resolver->resolve( $this->declaration( $type, $file ) )['code'] );
		}
	}

	public function testDeterministicCaptureFailuresAreUnreadable(): void
	{
		$file = $this->file('plugins/capture/main.php', $this->pluginHeader());
		foreach (array('lock' => static fn (): bool => false, 'rewind' => static fn (): bool => false, 'read' => static fn (mixed $stream, int $read): string|false => 2 === $read ? false : fread($stream, 8192)) as $property => $seam) {
			$resolver = $this->resolver();
			(new \ReflectionProperty(InstalledPackageResolver::class, $property))->setValue($resolver, $seam);
			self::assertSame('installed_file_unreadable', $resolver->resolve($this->declaration('plugin', $file))['code']);
		}
	}

	private function resolver(): InstalledPackageResolver { return new InstalledPackageResolver($this->root . '/plugins', array(), array($this->root . '/themes')); }
	/** @return array<string,mixed> */ private function declaration(string $type, string $file): array { return array('target_type' => $type, 'installed_file' => $file); }
	private function pluginHeader(string $suffix = "\n"): string { return "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/" . $suffix; }
	private function themeHeader(string $extra = ''): string { return "/*\nTheme Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n" . $extra . "\n*/\n"; }
	private function file(string $relative, string $contents): string { $path = $this->root . '/' . $relative; if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true); file_put_contents($path, $contents); return $path; }
	private function remove(string $path): void { if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; } foreach (scandir($path) ?: array() as $name) if ('.' !== $name && '..' !== $name) $this->remove($path . '/' . $name); rmdir($path); }
}
