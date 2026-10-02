<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReusableWorkflowTest extends TestCase
{
    use WorkflowYamlTrait;

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

    public function testNoJobOrStepIsAllowedToFailWithoutFailingTheWorkflow(): void
    {
        foreach (self::workflowMap('jobs') as $id => $job) {
            self::assertIsArray($job);
            self::assertArrayNotHasKey('continue-on-error', $job, sprintf('Job "%s" must be blocking', $id));

            foreach (is_array($job['steps'] ?? null) ? $job['steps'] : [] as $index => $step) {
                self::assertIsArray($step);
                self::assertArrayNotHasKey('continue-on-error', $step, sprintf('Step %s of job "%s" must be blocking', $index, $id));
            }
        }
    }

    public function testEveryInputIsDocumentedInTheReadme(): void
    {
        $readme = self::read(self::README);

        foreach (array_keys(self::workflowMap('on', 'workflow_call', 'inputs')) as $name) {
            self::assertStringContainsString(sprintf('| `%s` |', $name), $readme);
        }
    }

    public function testInfectionFailsBelowTheConfiguredMinimumMsi(): void
    {
        foreach (['infection-min-msi', 'infection-min-covered-msi'] as $name) {
            self::assertSame('number', self::workflowValue('on', 'workflow_call', 'inputs', $name, 'type'));
            self::assertSame(55, self::workflowValue('on', 'workflow_call', 'inputs', $name, 'default'));
            self::assertStringContainsString(sprintf('| `%s` | `55` |', $name), self::read(self::README));
        }

        $command = self::workflowStepRun('infection', 'vendor/bin/infection');

        self::assertStringContainsString('--min-msi=${{ inputs.infection-min-msi }}', $command);
        self::assertStringContainsString('--min-covered-msi=${{ inputs.infection-min-covered-msi }}', $command);
    }

    public function testPreferLowestCellIsAddedUnlessTheBundleOptsOut(): void
    {
        self::assertSame('boolean', self::workflowValue('on', 'workflow_call', 'inputs', 'run-prefer-lowest', 'type'));
        self::assertTrue(self::workflowValue('on', 'workflow_call', 'inputs', 'run-prefer-lowest', 'default'));

        self::assertSame(['highest'], self::workflowValue('jobs', 'phpunit', 'strategy', 'matrix', 'dependencies'));

        $include = self::workflowValue('jobs', 'phpunit', 'strategy', 'matrix', 'include');

        self::assertIsString($include);
        self::assertStringContainsString('inputs.run-prefer-lowest', $include);
        self::assertStringContainsString('"dependencies":"lowest"', $include);
        self::assertStringContainsString('fromJSON(inputs.php-versions)[0]', $include);
        self::assertStringContainsString('fromJSON(inputs.symfony-versions)[0]', $include);

        self::assertStringContainsString('--prefer-lowest', self::workflowStepRun('phpunit', 'composer update'));
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
