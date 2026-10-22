<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class VerifyStandardCliTest extends TestCase
{
    public function testReportsAnUnknownProfileAndChecksTheRestAgainstTheDefault(): void
    {
        $bundleDir = sys_get_temp_dir() . '/cli-bundle-' . uniqid('', true);
        mkdir($bundleDir);
        file_put_contents($bundleDir . '/composer.json', '{"require": {"php": ">=7.4"}, "extra": {"bundle-standard": {"runtime": "php80"}}}');

        try {
            exec(\sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../../bin/verify-standard.php'), escapeshellarg($bundleDir)), $output, $exitCode);
        } finally {
            unlink($bundleDir . '/composer.json');
            rmdir($bundleDir);
        }

        self::assertSame(1, $exitCode);
        self::assertContains('  - composer.json: extra.bundle-standard.runtime must be "php82" or "php84" or "php74", found "php80"', $output);
        self::assertContains('  - composer.json: must require php ">=8.2", found ">=7.4"', $output);
    }

    public function testChecksAnUnknownKindAsABundle(): void
    {
        [$exitCode, $output] = $this->verify('{"extra": {"bundle-standard": {"kind": "plugin"}}}');

        self::assertSame(1, $exitCode);
        self::assertContains('  - composer.json: extra.bundle-standard.kind must be "bundle" or "library", found "plugin"', $output);
        self::assertContains('  - composer.json: must declare extra.symfony.require', $output);
        self::assertContains('  - src: is missing: the bundle class and its layers live there', $output);
    }

    public function testChecksADeclaredLibraryAsALibrary(): void
    {
        [$exitCode, $output] = $this->verify('{"extra": {"bundle-standard": {"kind": "library"}}}');

        self::assertSame(1, $exitCode);
        self::assertNotContains('  - composer.json: must declare extra.symfony.require', $output);
        self::assertContains("  - src: is missing: the library's layers live there", $output);
    }

    public function testExitsWith2WhenATemplateIsBroken(): void
    {
        $root = sys_get_temp_dir() . '/cli-standard-' . uniqid('', true);
        $bundleDir = $root . '/bundle';
        mkdir($root . '/bin', 0o777, true);
        mkdir($root . '/templates');
        mkdir($bundleDir);
        copy(__DIR__ . '/../../bin/verify-standard.php', $root . '/bin/verify-standard.php');
        $vendor = realpath(__DIR__ . '/../../vendor');
        self::assertIsString($vendor);
        symlink($vendor, $root . '/vendor');
        file_put_contents($root . '/templates/deptrac.yaml', "deptrac: {}\n");

        try {
            exec(\sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($root . '/bin/verify-standard.php'), escapeshellarg($bundleDir)), $output, $exitCode);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        self::assertSame(2, $exitCode);
        self::assertStringStartsWith('The standard cannot be loaded: ', $output[0] ?? '');
    }

    public function testExitsWith2WhenTheBundleCannotBeRead(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root reads directories regardless of their mode');
        }

        $bundleDir = sys_get_temp_dir() . '/cli-locked-' . uniqid('', true);
        mkdir($bundleDir . '/src/Infrastructure/locked', 0o777, true);
        chmod($bundleDir . '/src/Infrastructure/locked', 0o000);

        try {
            exec(\sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../../bin/verify-standard.php'), escapeshellarg($bundleDir)), $output, $exitCode);
        } finally {
            chmod($bundleDir . '/src/Infrastructure/locked', 0o777);
            exec('rm -rf ' . escapeshellarg($bundleDir));
        }

        self::assertSame(2, $exitCode);
        self::assertStringStartsWith('The bundle cannot be read: ', $output[0] ?? '');
    }

    public function testExitsWith2WhenAFileOfTheBundleCannotBeRead(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root reads files regardless of their mode');
        }

        $bundleDir = sys_get_temp_dir() . '/cli-locked-file-' . uniqid('', true);
        mkdir($bundleDir . '/src/Infrastructure', 0o777, true);
        file_put_contents($bundleDir . '/src/Infrastructure/LockedPass.php', '<?php');
        chmod($bundleDir . '/src/Infrastructure/LockedPass.php', 0o000);

        try {
            exec(\sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../../bin/verify-standard.php'), escapeshellarg($bundleDir)), $output, $exitCode);
        } finally {
            exec('rm -rf ' . escapeshellarg($bundleDir));
        }

        self::assertSame(2, $exitCode);
        self::assertSame('The bundle cannot be read: cannot read src/Infrastructure/LockedPass.php', $output[0] ?? '');
    }

    /**
     * @return array{int, list<string>}
     */
    private function verify(string $manifest): array
    {
        $bundleDir = sys_get_temp_dir() . '/cli-bundle-' . uniqid('', true);
        mkdir($bundleDir);
        file_put_contents($bundleDir . '/composer.json', $manifest);

        try {
            exec(\sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../../bin/verify-standard.php'), escapeshellarg($bundleDir)), $output, $exitCode);
        } finally {
            unlink($bundleDir . '/composer.json');
            rmdir($bundleDir);
        }

        return [$exitCode, $output];
    }
}
