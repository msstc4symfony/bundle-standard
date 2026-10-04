<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use Msstc4Symfony\BundleStandard\Rule\RuntimeProfileRule;
use Msstc4Symfony\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeProfileRule::class)]
final class RuntimeProfileRuleTest extends TestCase
{
    /** @var non-empty-string */
    private string $bundleDir;

    protected function setUp(): void
    {
        $this->bundleDir = sys_get_temp_dir() . '/runtime-rule-' . uniqid('', true);
        mkdir($this->bundleDir);
    }

    protected function tearDown(): void
    {
        if (is_file($this->bundleDir . '/composer.json')) {
            unlink($this->bundleDir . '/composer.json');
        }

        rmdir($this->bundleDir);
    }

    public function testAcceptsAMissingDeclaration(): void
    {
        self::assertSame([], (new RuntimeProfileRule())->check($this->bundleDir));
    }

    public function testAcceptsAKnownDeclaration(): void
    {
        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": "php74"}}}');
        self::assertSame([], (new RuntimeProfileRule())->check($this->bundleDir));
    }

    public function testReportsAnUnknownDeclaration(): void
    {
        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": "php80"}}}');

        self::assertSame(
            ['composer.json: extra.bundle-standard.runtime must be "php84" or "php74", found "php80"'],
            array_map(static fn (Violation $v): string => $v->format(), (new RuntimeProfileRule())->check($this->bundleDir)),
        );

        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": 74}}}');

        self::assertSame(
            ['composer.json: extra.bundle-standard.runtime must be "php84" or "php74", found 74'],
            array_map(static fn (Violation $v): string => $v->format(), (new RuntimeProfileRule())->check($this->bundleDir)),
        );
    }
}
