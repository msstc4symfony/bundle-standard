<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Msstc4Symfony\BundleStandard\PackageKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageKind::class)]
final class PackageKindTest extends TestCase
{
    /** @var non-empty-string */
    private string $bundleDir;

    protected function setUp(): void
    {
        $this->bundleDir = sys_get_temp_dir() . '/kind-package-' . uniqid('', true);
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
    public function testDefaultsToBundleWithoutADeclaration(?string $manifest): void
    {
        if ($manifest !== null) {
            file_put_contents($this->bundleDir . '/composer.json', $manifest);
        }

        self::assertSame(PackageKind::Bundle, PackageKind::declaredBy($this->bundleDir));
        self::assertNull(PackageKind::declaration($this->bundleDir));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function manifestsWithoutADeclaration(): iterable
    {
        yield 'no manifest' => [null];
        yield 'no extra' => ['{"name": "msstc4symfony/foo"}'];
        yield 'only a runtime' => ['{"extra": {"bundle-standard": {"runtime": "php84"}}}'];
        yield 'unreadable manifest' => ['{'];
        yield 'bundle-standard is not an object' => ['{"extra": {"bundle-standard": "library"}}'];
    }

    public function testReadsTheDeclaredKind(): void
    {
        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"kind": "library"}}}');
        self::assertSame(PackageKind::Library, PackageKind::declaredBy($this->bundleDir));

        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"kind": "bundle", "runtime": "php74"}}}');
        self::assertSame(PackageKind::Bundle, PackageKind::declaredBy($this->bundleDir));
    }

    public function testHasNoKindForAnUnknownDeclaration(): void
    {
        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"kind": "Library"}}}');
        self::assertNull(PackageKind::declaredBy($this->bundleDir));
        self::assertSame('"Library"', PackageKind::declaration($this->bundleDir));

        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"kind": ["library"]}}}');
        self::assertNull(PackageKind::declaredBy($this->bundleDir));
        self::assertSame('["library"]', PackageKind::declaration($this->bundleDir));
    }
}
