<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard\Test\Unit\Rule;

use MaxShamaev\BundleStandard\Rule\FileAbsentRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileAbsentRule::class)]
final class FileAbsentRuleTest extends TestCase
{
    /** @var non-empty-string */
    private string $bundleDir;

    protected function setUp(): void
    {
        $this->bundleDir = sys_get_temp_dir() . '/bundle-' . uniqid('', true);
        mkdir($this->bundleDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->bundleDir . '/*');

        foreach (($files !== false ? $files : []) as $file) {
            unlink($file);
        }

        rmdir($this->bundleDir);
    }

    public function testPassesWhenFileIsAbsent(): void
    {
        $rule = new FileAbsentRule('psalm.xml', 'the standard uses PHPStan only');

        self::assertSame([], $rule->check($this->bundleDir));
    }

    public function testReportsWhenFileExists(): void
    {
        file_put_contents($this->bundleDir . '/psalm.xml', '<psalm/>');

        $rule = new FileAbsentRule('psalm.xml', 'the standard uses PHPStan only');
        $violations = $rule->check($this->bundleDir);

        self::assertCount(1, $violations);
        self::assertSame('must not exist: the standard uses PHPStan only', $violations[0]->message);
    }
}
