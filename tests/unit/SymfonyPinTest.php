<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Msstc4Symfony\BundleStandard\PackageKind;
use Msstc4Symfony\BundleStandard\Test\Unit\Rule\TemporaryBundleTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Executes the "Pin Symfony version" and "Assert the matrix Symfony version was installed" step scripts of
 * php-bundle.yml's phpunit job against a stub composer that logs its arguments.
 */
#[CoversNothing]
final class SymfonyPinTest extends TestCase
{
    use TemporaryBundleTrait;
    use WorkflowYamlTrait;

    protected function setUp(): void
    {
        if (!self::hasJq()) {
            // CI must run these scripts: a missing jq there would hide a broken workflow.
            getenv('CI') !== false ? self::fail('jq is not installed') : self::markTestSkipped('jq is not installed');
        }

        $this->createBundle();
        $this->write('bin/composer', "#!/bin/sh\necho \"\$*\" >> \"\$(dirname \"\$0\")/../composer.log\"\nif [ \"\$1\" = show ]; then echo \"{\\\"versions\\\":[\\\"\${STUB_VERSION}\\\"]}\"; fi\n");
        chmod($this->bundleDir . '/bin/composer', 0o755);
        $this->write('github-env', '');
    }

    protected function tearDown(): void
    {
        if (isset($this->bundleDir)) {
            $this->removeBundle();
        }
    }

    public function testPinsTheKernelCoreAndTheListedComponentsOfABundle(): void
    {
        $this->write('composer.json', '{}');
        $this->write('composer-ci.json', '{"require": {"symfony/yaml": "^7.4|^8.0", "symfony/polyfill-php83": "^1.31"}, "require-dev": {"symfony/browser-kit": "^7.4|^8.0", "symfony/phpunit-bridge": "^7.4"}}');

        [$exitCode, $output] = $this->runStep('Pin Symfony version', ['SYMFONY_VERSION' => '8.*']);

        self::assertSame(0, $exitCode, $output);
        $pinned = 'symfony/browser-kit symfony/config symfony/console symfony/dependency-injection symfony/dom-crawler symfony/framework-bundle symfony/http-kernel symfony/yaml';
        self::assertSame(['require --no-update --no-interaction ' . implode(' ', array_map(static fn (string $p): string => $p . ':8.*', explode(' ', $pinned)))], $this->composerCalls());
        self::assertSame("SYMFONY_PROBE=symfony/http-kernel\n", $this->githubEnv());
    }

    public function testPinsOnlyTheListedComponentsOfALibrary(): void
    {
        $this->write('composer.json', '{"extra": {"bundle-standard": {"kind": "library"}}}');
        $this->write('composer-ci.json', '{"require": {"symfony/yaml": "^7.4|^8.0"}, "require-dev": {"symfony/console": "^7.4|^8.0"}}');

        [$exitCode, $output] = $this->runStep('Pin Symfony version', ['SYMFONY_VERSION' => '7.4.*']);

        self::assertSame(0, $exitCode, $output);
        self::assertSame(['require --no-update --no-interaction symfony/console:7.4.* symfony/yaml:7.4.*'], $this->composerCalls());
        self::assertSame("SYMFONY_PROBE=symfony/console\n", $this->githubEnv());
    }

    public function testPinsDomCrawlerWithBrowserKitInALibrary(): void
    {
        $this->write('composer.json', '{"extra": {"bundle-standard": {"kind": "library"}}}');
        $this->write('composer-ci.json', '{"require-dev": {"symfony/browser-kit": "^7.4|^8.0"}}');

        [$exitCode, $output] = $this->runStep('Pin Symfony version', ['SYMFONY_VERSION' => '8.*']);

        self::assertSame(0, $exitCode, $output);
        self::assertSame(['require --no-update --no-interaction symfony/browser-kit:8.* symfony/dom-crawler:8.*'], $this->composerCalls());
        self::assertSame("SYMFONY_PROBE=symfony/browser-kit\n", $this->githubEnv());
    }

    public function testPinsNothingForALibraryWithoutSymfony(): void
    {
        $this->write('composer.json', '{"extra": {"bundle-standard": {"kind": "library"}}}');
        $this->write('composer-ci.json', '{"require": {"php": ">=8.2"}, "require-dev": {"phpunit/phpunit": "^11"}}');

        [$exitCode, $output] = $this->runStep('Pin Symfony version', ['SYMFONY_VERSION' => 'none']);

        self::assertSame(0, $exitCode, $output);
        self::assertSame([], $this->composerCalls());
        self::assertStringContainsString('No Symfony package to pin', $output);
        self::assertSame("SYMFONY_PROBE=none\n", $this->githubEnv());

        [$exitCode, $output] = $this->runStep('Assert the matrix Symfony version was installed', ['SYMFONY_LABEL' => 'none', 'SYMFONY_PROBE' => 'none']);

        self::assertSame(0, $exitCode, $output);
        self::assertSame([], $this->composerCalls());
    }

    public function testTheKindIsReadFromComposerJsonLikeTheVerifierDoes(): void
    {
        self::assertStringContainsString(
            sprintf('jq -r \'.extra["bundle-standard"].kind // "%s"\' composer.json)" = %s', PackageKind::Bundle->value, PackageKind::Library->value),
            self::step('Pin Symfony version'),
        );
    }

    public function testAssertsTheProbedPackageVersion(): void
    {
        [$exitCode, $output] = $this->runStep('Assert the matrix Symfony version was installed', ['SYMFONY_LABEL' => '7.4', 'SYMFONY_PROBE' => 'symfony/yaml', 'STUB_VERSION' => 'v7.4.2']);

        self::assertSame(0, $exitCode, $output);
        self::assertSame(['show symfony/yaml --format=json'], $this->composerCalls());

        [$exitCode, $output] = $this->runStep('Assert the matrix Symfony version was installed', ['SYMFONY_LABEL' => '7.4', 'STUB_VERSION' => 'v8.0.1']);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('::error::Matrix expects Symfony 7.4, resolved symfony/http-kernel v8.0.1', $output);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private function runStep(string $stepName, array $env): array
    {
        // stderr joins stdout inside bash: one pipe cannot deadlock on a full second one.
        $process = proc_open(
            ['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', '{ ' . self::step($stepName) . "\n} 2>&1"],
            [1 => ['pipe', 'w']],
            $pipes,
            $this->bundleDir,
            [
                'PATH' => $this->bundleDir . '/bin' . PATH_SEPARATOR . self::path(),
                'COMPOSER' => 'composer-ci.json',
                'GITHUB_ENV' => $this->bundleDir . '/github-env',
                ...$env,
            ],
        );
        self::assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    private static function step(string $name): string
    {
        foreach (self::workflowMap('jobs', 'phpunit', 'steps') as $step) {
            if (\is_array($step) && ($step['name'] ?? null) === $name && \is_string($step['run'] ?? null)) {
                return $step['run'];
            }
        }

        self::fail(sprintf('The phpunit job has no step "%s"', $name));
    }

    /**
     * @return list<string>
     */
    private function composerCalls(): array
    {
        $log = $this->bundleDir . '/composer.log';
        $calls = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
        if (is_file($log)) {
            unlink($log);
        }

        return $calls === false ? [] : $calls;
    }

    private function githubEnv(): string
    {
        return (string) file_get_contents($this->bundleDir . '/github-env');
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
}
