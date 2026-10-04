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
        self::assertContains('  - composer.json: extra.bundle-standard.runtime must be "php84" or "php74", found "php80"', $output);
        self::assertContains('  - composer.json: must require php ">=8.4", found ">=7.4"', $output);
    }
}
