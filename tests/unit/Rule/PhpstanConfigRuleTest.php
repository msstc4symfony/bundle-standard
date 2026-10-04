<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use Msstc4Symfony\BundleStandard\Rule\PhpstanConfigRule;
use Msstc4Symfony\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpstanConfigRule::class)]
final class PhpstanConfigRuleTest extends TestCase
{
    private const string TEMPLATE = "parameters:\n    level: 9\n    phpVersion: 80400\n    paths:\n        - src/\n";

    /** @var non-empty-string */
    private string $bundleDir;

    /** @var non-empty-string */
    private string $templateFile;

    protected function setUp(): void
    {
        $this->bundleDir = sys_get_temp_dir() . '/bundle-' . uniqid('', true);
        mkdir($this->bundleDir);

        $this->templateFile = sys_get_temp_dir() . '/template-' . uniqid('', true);
        file_put_contents($this->templateFile, self::TEMPLATE);
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

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function acceptedLevels(): iterable
    {
        yield 'template level' => ['9'];
        yield 'level 10' => ['10'];
        yield 'max' => ['max'];
    }

    #[DataProvider('acceptedLevels')]
    public function testAcceptsTheTemplateAtLevelNineOrStricter(string $level): void
    {
        $this->writeConfig(str_replace('level: 9', 'level: ' . $level, self::TEMPLATE));

        self::assertSame([], $this->messages());
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function rejectedLevels(): iterable
    {
        yield 'level 8' => ['8'];
        yield 'level 0' => ['0'];
        yield 'not a level' => ['strict'];
        yield 'negative' => ['-1'];
        yield 'above the highest PHPStan level' => ['11'];
        yield 'zero-padded' => ['09'];
    }

    #[DataProvider('rejectedLevels')]
    public function testReportsALevelOutsideTheAcceptedSet(string $level): void
    {
        $this->writeConfig(str_replace('level: 9', 'level: ' . $level, self::TEMPLATE));

        self::assertSame(
            [sprintf('PHPStan level must be one of 9, 10, max, found "%s"', $level)],
            $this->messages(),
        );
    }

    public function testAcceptsATrailingCommentOnTheLevelLine(): void
    {
        $this->writeConfig(str_replace('level: 9', 'level: 10 # raised ahead of the template', self::TEMPLATE));

        self::assertSame([], $this->messages());
    }

    public function testReportsASecondLevelLine(): void
    {
        $this->writeConfig(self::TEMPLATE . "    level: 0\n");

        self::assertSame(
            ['differs from the standard template beyond the PHPStan level; run `diff` against bundle-standard/templates'],
            $this->messages(),
        );
    }

    public function testReportsAMissingLevel(): void
    {
        $this->writeConfig(str_replace("    level: 9\n", '', self::TEMPLATE));

        self::assertSame(['must declare a PHPStan level'], $this->messages());
    }

    public function testReportsAnyOtherDifferenceFromTheTemplate(): void
    {
        $this->writeConfig(str_replace('level: 9', 'level: 10', self::TEMPLATE) . "    tmpDir: var/\n");

        self::assertSame(
            ['differs from the standard template beyond the PHPStan level; run `diff` against bundle-standard/templates'],
            $this->messages(),
        );
    }

    public function testReportsAMissingFile(): void
    {
        $violations = $this->check();

        self::assertCount(1, $violations);
        self::assertSame('phpstan.dist.neon', $violations[0]->file);
        self::assertSame('is missing', $violations[0]->message);
    }

    private function writeConfig(string $contents): void
    {
        file_put_contents($this->bundleDir . '/phpstan.dist.neon', $contents);
    }

    /**
     * @return list<Violation>
     */
    private function check(): array
    {
        return (new PhpstanConfigRule('phpstan.dist.neon', $this->templateFile))->check($this->bundleDir);
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(static fn (Violation $v): string => $v->message, $this->check());
    }
}
