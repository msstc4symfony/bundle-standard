<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Msstc4Symfony\BundleStandard\RuntimeProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeProfile::class)]
final class RuntimeProfileTest extends TestCase
{
    private const string TEMPLATES_DIR = __DIR__ . '/../../templates';

    /** @var non-empty-string */
    private string $bundleDir;

    protected function setUp(): void
    {
        $this->bundleDir = sys_get_temp_dir() . '/profile-bundle-' . uniqid('', true);
        mkdir($this->bundleDir);
    }

    protected function tearDown(): void
    {
        if (is_file($this->bundleDir . '/composer.json')) {
            unlink($this->bundleDir . '/composer.json');
        }

        rmdir($this->bundleDir);
    }

    #[DataProvider('manifestsWithoutADeclaration')]
    public function testDefaultsToPhp84WithoutADeclaration(?string $manifest): void
    {
        if ($manifest !== null) {
            file_put_contents($this->bundleDir . '/composer.json', $manifest);
        }

        self::assertSame(RuntimeProfile::Php84, RuntimeProfile::declaredBy($this->bundleDir));
    }

    /**
     * An unreadable manifest is ComposerManifestRule's to report; the profile falls back to the default.
     *
     * @return iterable<string, array{string|null}>
     */
    public static function manifestsWithoutADeclaration(): iterable
    {
        yield 'no manifest' => [null];
        yield 'no extra' => ['{"name": "msstc4symfony/foo"}'];
        yield 'empty bundle-standard' => ['{"extra": {"bundle-standard": {}}}'];
        yield 'unreadable manifest' => ['{'];
        yield 'extra is not an object' => ['{"extra": "none"}'];
    }

    public function testReadsTheDeclaredProfile(): void
    {
        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": "php74"}}}');
        self::assertSame(RuntimeProfile::Php74, RuntimeProfile::declaredBy($this->bundleDir));

        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": "php84"}}}');
        self::assertSame(RuntimeProfile::Php84, RuntimeProfile::declaredBy($this->bundleDir));
    }

    public function testHasNoProfileForAnUnknownDeclaration(): void
    {
        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": "php80"}}}');
        self::assertNull(RuntimeProfile::declaredBy($this->bundleDir));

        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": 74}}}');
        self::assertNull(RuntimeProfile::declaredBy($this->bundleDir));
        self::assertSame('74', RuntimeProfile::declaration($this->bundleDir));

        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": ["php74"]}}}');
        self::assertNull(RuntimeProfile::declaredBy($this->bundleDir));
        self::assertSame('["php74"]', RuntimeProfile::declaration($this->bundleDir));
    }

    public function testKnowsItsPhpFloor(): void
    {
        self::assertSame('>=8.4', RuntimeProfile::Php84->phpConstraint());
        self::assertSame('>=7.4', RuntimeProfile::Php74->phpConstraint());
        self::assertSame('8.4', RuntimeProfile::Php84->minimalPhp());
        self::assertSame('7.4', RuntimeProfile::Php74->minimalPhp());
        self::assertSame('^7.4|^8.0', RuntimeProfile::Php84->symfonyConstraint());
        self::assertSame('^5.4|^6.4|^7.0|^8.0', RuntimeProfile::Php74->symfonyConstraint());
    }

    public function testPrefersTheProfilesOwnTemplate(): void
    {
        self::assertSame(self::TEMPLATES_DIR . '/phpstan.dist.neon', RuntimeProfile::Php84->template(self::TEMPLATES_DIR, 'phpstan.dist.neon'));
        self::assertSame(self::TEMPLATES_DIR . '/php74/phpstan.dist.neon', RuntimeProfile::Php74->template(self::TEMPLATES_DIR, 'phpstan.dist.neon'));
        self::assertSame(self::TEMPLATES_DIR . '/Makefile', RuntimeProfile::Php74->template(self::TEMPLATES_DIR, 'Makefile'));
    }
}
