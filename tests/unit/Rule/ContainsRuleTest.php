<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard\Test\Unit\Rule;

use MaxShamaev\BundleStandard\Rule\ContainsRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContainsRule::class)]
final class ContainsRuleTest extends TestCase
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

    public function testPassesWhenAllSubstringsPresent(): void
    {
        file_put_contents($this->bundleDir . '/rector.php', '->withPhpSets(php84: true)');

        $rule = new ContainsRule('rector.php', ['withPhpSets(php84: true)']);

        self::assertSame([], $rule->check($this->bundleDir));
    }

    public function testReportsEachMissingSubstring(): void
    {
        file_put_contents($this->bundleDir . '/phpstan.dist.neon', "level: 9\n");

        $rule = new ContainsRule('phpstan.dist.neon', ['level: 9', 'phpVersion: 80400']);
        $violations = $rule->check($this->bundleDir);

        self::assertCount(1, $violations);
        self::assertStringContainsString('phpVersion: 80400', $violations[0]->message);
    }

    public function testReportsWhenFileIsMissing(): void
    {
        $rule = new ContainsRule('rector.php', ['withPhpSets(php84: true)']);
        $violations = $rule->check($this->bundleDir);

        self::assertCount(1, $violations);
        self::assertStringContainsString('is missing', $violations[0]->message);
    }
}
