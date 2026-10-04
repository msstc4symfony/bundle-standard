<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use Msstc4Symfony\BundleStandard\Rule\FileExistsRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileExistsRule::class)]
final class FileExistsRuleTest extends TestCase
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

    public function testPassesWhenFileExists(): void
    {
        file_put_contents($this->bundleDir . '/deptrac.yaml', 'deptrac:');

        $rule = new FileExistsRule('deptrac.yaml', 'layer rules are mandatory');

        self::assertSame([], $rule->check($this->bundleDir));
    }

    public function testReportsWhenFileIsMissing(): void
    {
        $rule = new FileExistsRule('deptrac.yaml', 'layer rules are mandatory');
        $violations = $rule->check($this->bundleDir);

        self::assertCount(1, $violations);
        self::assertSame('deptrac.yaml', $violations[0]->file);
        self::assertSame('is required by the standard: layer rules are mandatory', $violations[0]->message);
    }
}
