<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Msstc4Symfony\BundleStandard\RuntimeProfile;
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

    public function testDefaultSymfonyMatrixStartsAtTheSupportedFloorAndCoversOnTheLatest(): void
    {
        $default = self::workflowValue('on', 'workflow_call', 'inputs', 'symfony-versions', 'default');
        self::assertIsString($default);

        $cells = json_decode($default, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($cells);

        self::assertSame(['7.4', '8'], array_column($cells, 'label'));
        self::assertSame([false, true], array_column($cells, 'codecov'));
        self::assertStringNotContainsString('6.4', $default);
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

    public function testMinimalJobRunsOnThePhpOfTheDeclaredRuntimeProfile(): void
    {
        self::assertArrayNotHasKey('minimal-php', self::workflowMap('on', 'workflow_call', 'inputs'));

        $runtime = null;
        $setup = null;
        foreach (self::workflowMap('jobs', 'minimal', 'steps') as $step) {
            if (is_array($step) && ($step['id'] ?? null) === 'runtime') {
                $runtime = $step;
            }

            if (is_array($step) && ($step['uses'] ?? null) === 'shivammathur/setup-php@v2') {
                $setup = $step;
            }
        }

        self::assertIsArray($runtime);
        $script = $runtime['run'] ?? null;
        self::assertIsString($script);
        self::assertStringContainsString('.extra["bundle-standard"].runtime // "php84"', $script);
        foreach (RuntimeProfile::cases() as $profile) {
            self::assertStringContainsString(sprintf('%s) php=%s ;;', $profile->value, $profile->minimalPhp()), $script);
        }

        self::assertIsArray($setup);
        $with = $setup['with'] ?? null;
        self::assertIsArray($with);
        self::assertSame('${{ steps.runtime.outputs.php }}', $with['php-version'] ?? null);
    }

    public function testMinimalJobLintsEverySourceOnAnOlderPhp(): void
    {
        // php -l checks one file per call before PHP 8.3; the rest of a batch would go unchecked.
        self::assertStringContainsString('xargs -r -P4 -n1 php -l', self::workflowStepRun('minimal', 'php -l'));

        foreach (self::workflowMap('jobs', 'minimal', 'steps') as $step) {
            if (is_array($step) && is_string($step['run'] ?? null) && str_contains($step['run'], 'php -l')) {
                self::assertSame('steps.runtime.outputs.php != env.PRIMARY_PHP', $step['if'] ?? null);
            }
        }
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
