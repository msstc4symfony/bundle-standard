<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReusableWorkflowTest extends TestCase
{
    private const string WORKFLOW = __DIR__ . '/../../.github/workflows/php-bundle.yml';

    private const string README = __DIR__ . '/../../README.md';

    public function testStandardCheckoutIsPinnedToAThreePartReleaseTag(): void
    {
        self::assertMatchesRegularExpression('/^v\d+\.\d+\.\d+$/', $this->workflowStandardRef());
    }

    public function testReadmeExamplesUseTheSameTagAsTheWorkflow(): void
    {
        $readme = self::read(self::README);

        preg_match_all('#php-bundle\.yml@(\S+)#', $readme, $matches);

        self::assertNotEmpty($matches[1]);
        self::assertSame(
            [$this->workflowStandardRef()],
            array_values(array_unique($matches[1])),
        );
    }

    private function workflowStandardRef(): string
    {
        if (preg_match('#repository: msstc4symfony/bundle-standard\s+ref: (\S+)#', self::read(self::WORKFLOW), $match) !== 1) {
            self::fail('php-bundle.yml has no bundle-standard checkout with a ref');
        }

        return $match[1];
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            self::fail('Cannot read ' . $path);
        }

        return $contents;
    }
}
