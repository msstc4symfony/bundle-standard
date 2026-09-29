<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use Msstc4Symfony\BundleStandard\Rule\ExactFileRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExactFileRule::class)]
final class ExactFileRuleTest extends TestCase
{
    /** @var non-empty-string */
    private string $bundleDir;

    /** @var non-empty-string */
    private string $templateFile;

    protected function setUp(): void
    {
        $this->bundleDir = sys_get_temp_dir() . '/bundle-' . uniqid('', true);
        mkdir($this->bundleDir);

        $this->templateFile = sys_get_temp_dir() . '/template-' . uniqid('', true);
        file_put_contents($this->templateFile, "line one\nline two\n");
    }

    protected function tearDown(): void
    {
        $files = glob($this->bundleDir . '/*');

        foreach (($files !== false ? $files : []) as $file) {
            unlink($file);
        }

        rmdir($this->bundleDir);
        unlink($this->templateFile);
    }

    public function testPassesWhenContentIsIdentical(): void
    {
        file_put_contents($this->bundleDir . '/Makefile', "line one\nline two\n");

        $rule = new ExactFileRule('Makefile', $this->templateFile);

        self::assertSame([], $rule->check($this->bundleDir));
    }

    public function testReportsWhenContentDiffers(): void
    {
        file_put_contents($this->bundleDir . '/Makefile', "line one\ndifferent\n");

        $rule = new ExactFileRule('Makefile', $this->templateFile);
        $violations = $rule->check($this->bundleDir);

        self::assertCount(1, $violations);
        self::assertSame('Makefile', $violations[0]->file);
        self::assertStringContainsString('differs from the standard template', $violations[0]->message);
    }

    public function testReportsWhenFileIsMissing(): void
    {
        $rule = new ExactFileRule('Makefile', $this->templateFile);
        $violations = $rule->check($this->bundleDir);

        self::assertCount(1, $violations);
        self::assertStringContainsString('is missing', $violations[0]->message);
    }
}
