<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Msstc4Symfony\BundleStandard\RuntimeProfile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executes the "cells" step script of php-bundle.yml's matrix job to pin down which PHPUnit cells it builds.
 */
#[CoversNothing]
final class PhpunitMatrixTest extends TestCase
{
    use WorkflowYamlTrait;

    /** @var non-empty-string */
    private string $bundleDir;

    protected function setUp(): void
    {
        if (!self::hasJq()) {
            self::markTestSkipped('jq is not installed');
        }

        $this->bundleDir = sys_get_temp_dir() . '/phpunit-matrix-' . uniqid('', true);
        mkdir($this->bundleDir);
        file_put_contents($this->bundleDir . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        foreach (['composer.json', 'output'] as $file) {
            if (is_file($this->bundleDir . '/' . $file)) {
                unlink($this->bundleDir . '/' . $file);
            }
        }

        if (is_dir($this->bundleDir)) {
            rmdir($this->bundleDir);
        }
    }

    public function testDefaultsSkipSymfony8BelowPhp84AndAddTheLowestCellOnTheFirstPair(): void
    {
        self::assertSame(
            ['8.2/7.4.*/highest', '8.3/7.4.*/highest', '8.4/7.4.*/highest', '8.4/8.*/highest', '8.5/7.4.*/highest', '8.5/8.*/highest', '8.2/7.4.*/lowest'],
            $this->cells(self::defaultInput('php-versions'), self::defaultInput('symfony-versions'), 'true'),
        );
    }

    public function testNoLowestCellWhenTheBundleOptsOut(): void
    {
        self::assertNotContains('8.2/7.4.*/lowest', $this->cells(self::defaultInput('php-versions'), self::defaultInput('symfony-versions'), 'false'));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('profiles')]
    public function testSkipsVersionsBelowTheRuntimeProfile(string $runtime, array $expected): void
    {
        file_put_contents($this->bundleDir . '/composer.json', sprintf('{"extra": {"bundle-standard": {"runtime": "%s"}}}', $runtime));

        self::assertSame($expected, $this->cells('["7.4","8.2","8.4"]', '[{"version":"6.4.*","label":"6.4","codecov":false}]', 'true'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function profiles(): iterable
    {
        yield 'php74' => ['php74', ['7.4/6.4.*/highest', '8.2/6.4.*/highest', '8.4/6.4.*/highest', '7.4/6.4.*/lowest']];
        yield 'php82' => ['php82', ['8.2/6.4.*/highest', '8.4/6.4.*/highest', '8.2/6.4.*/lowest']];
        yield 'php84' => ['php84', ['8.4/6.4.*/highest', '8.4/6.4.*/lowest']];
    }

    public function testEveryProfileHasItsFloorInTheScript(): void
    {
        foreach (RuntimeProfile::cases() as $profile) {
            self::assertStringContainsString(sprintf('%s) php=%s ;;', $profile->value, $profile->minimalPhp()), self::script());
        }
    }

    public function testTheLowestCellSkipsAFirstSymfonyEntryAboveTheFirstPhp(): void
    {
        self::assertSame(
            ['8.4/8.*/highest', '8.4/8.*/lowest'],
            $this->cells('["8.2","8.4"]', '[{"version":"8.*","label":"8","codecov":true,"php":"8.4"}]', 'true'),
        );
    }

    public function testNumericAndNonNumericVersionsAreCompared(): void
    {
        self::assertSame(
            ['8.4/8.*/highest', 'nightly/8.*/highest'],
            $this->cells('[8.2,8.4,"nightly"]', '[{"version":"8.*","label":"8","codecov":true,"php":"8.4"}]', 'false'),
        );
    }

    public function testFailsOnAnUnknownRuntimeProfile(): void
    {
        file_put_contents($this->bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": "php80"}}}');

        [$exitCode, $output] = $this->runScript('["8.4"]', '[{"version":"8.*","label":"8","codecov":true}]', 'false');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('::error::Unknown runtime profile php80', $output);
    }

    public function testWarnsWhenCodecovIsOnButNoCellUploads(): void
    {
        [, $withPrimary] = $this->runScript('["8.4"]', '[{"version":"8.*","label":"8","codecov":true}]', 'false', 'true');
        [, $withoutPrimary] = $this->runScript('["8.5"]', '[{"version":"8.*","label":"8","codecov":true}]', 'false', 'true');
        [, $codecovOff] = $this->runScript('["8.5"]', '[{"version":"8.*","label":"8","codecov":true}]', 'false');

        self::assertStringNotContainsString('::warning::', $withPrimary);
        self::assertStringContainsString('::warning::run-codecov is on but no cell runs PHP 8.4', $withoutPrimary);
        self::assertStringNotContainsString('::warning::', $codecovOff);
    }

    public function testFailsWhenNoCellRemains(): void
    {
        [$exitCode, $output] = $this->runScript('["8.2"]', '[{"version":"8.*","label":"8","codecov":true,"php":"8.4"}]', 'true');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('::error::No php-versions entry', $output);
    }

    /**
     * @return list<string> "php/symfony/dependencies" per cell, in order
     */
    private function cells(string $phpVersions, string $symfonyVersions, string $preferLowest): array
    {
        [$exitCode, $output] = $this->runScript($phpVersions, $symfonyVersions, $preferLowest);
        self::assertSame(0, $exitCode, $output);

        $written = (string) file_get_contents($this->bundleDir . '/output');
        self::assertStringStartsWith('include=', $written);

        $cells = json_decode(substr(trim($written), \strlen('include=')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($cells);

        return array_values(array_map(static function (mixed $cell): string {
            self::assertIsArray($cell);
            self::assertIsArray($cell['symfony']);

            return sprintf('%s/%s/%s', self::scalar($cell['php']), self::scalar($cell['symfony']['version']), self::scalar($cell['dependencies']));
        }, $cells));
    }

    /**
     * @return array{int, string}
     */
    private function runScript(string $phpVersions, string $symfonyVersions, string $preferLowest, string $runCodecov = 'false'): array
    {
        // stderr joins stdout inside bash: one pipe cannot deadlock on a full second one.
        $process = proc_open(
            ['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', '{ ' . self::script() . "\n} 2>&1"],
            [1 => ['pipe', 'w']],
            $pipes,
            $this->bundleDir,
            [
                'PATH' => self::path(),
                'PHP_VERSIONS' => $phpVersions,
                'SYMFONY_VERSIONS' => $symfonyVersions,
                'PREFER_LOWEST' => $preferLowest,
                'RUN_CODECOV' => $runCodecov,
                'PRIMARY_PHP' => self::scalar(self::workflowValue('env', 'PRIMARY_PHP')),
                'GITHUB_OUTPUT' => $this->bundleDir . '/output',
            ],
        );
        self::assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    private static function hasJq(): bool
    {
        foreach (explode(PATH_SEPARATOR, self::path()) as $dir) {
            if ($dir !== '' && is_executable($dir . '/jq')) {
                return true;
            }
        }

        return false;
    }

    private static function path(): string
    {
        $path = getenv('PATH');

        return \is_string($path) && $path !== '' ? $path : '/usr/bin:/bin';
    }

    private static function script(): string
    {
        return self::workflowStepRun('matrix', 'jq -cn');
    }

    private static function defaultInput(string $input): string
    {
        $default = self::workflowValue('on', 'workflow_call', 'inputs', $input, 'default');
        self::assertIsString($default);

        return $default;
    }

    private static function scalar(mixed $value): string
    {
        self::assertTrue(\is_string($value) || \is_int($value) || \is_float($value));

        return (string) $value;
    }
}
