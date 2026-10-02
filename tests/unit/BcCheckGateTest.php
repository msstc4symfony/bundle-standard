<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Executes the "Roave BC check" step script of php-bundle.yml against a throwaway git repository
 * whose roave binary is a stub, to pin down when the blocking BC check runs and when it is skipped.
 */
#[CoversNothing]
final class BcCheckGateTest extends TestCase
{
    use WorkflowYamlTrait;

    private const string STUB_MARKER = 'roave-stub-ran';

    /** @var non-empty-string */
    private string $repository;

    protected function setUp(): void
    {
        $this->repository = sys_get_temp_dir() . '/bc-gate-' . uniqid('', true);
        mkdir($this->repository . '/vendor/bin', 0o777, true);
        file_put_contents(
            $this->repository . '/vendor/bin/roave-backward-compatibility-check',
            "#!/bin/sh\necho " . self::STUB_MARKER . " \"\$@\"\n",
        );
        chmod($this->repository . '/vendor/bin/roave-backward-compatibility-check', 0o755);
        file_put_contents($this->repository . '/composer.json', '{}');

        $this->git('init', '--quiet');
        $this->git('add', 'composer.json');
        $this->git('commit', '--quiet', '-m', 'initial');
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->repository, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->repository);
    }

    /**
     * @return iterable<string, array{non-empty-list<non-empty-string>, non-empty-string, string, string}>
     */
    public static function blockingCases(): iterable
    {
        yield 'push to main' => [['v1.3.0'], 'v1.3.0', 'main', ''];
        yield 'pull request into main' => [['v1.3.0'], 'v1.3.0', 'feature/x', 'main'];
        yield 'maintenance branch of the current major' => [['v1.3.0'], 'v1.3.0', '1.x', ''];
        yield 'branch of an older major' => [['v2.1.0'], 'v2.1.0', '1.x', ''];
        yield 'stable release of the branch major' => [['v1.3.0', 'v2.0.0'], 'v2.0.0', '2.x', ''];
        yield 'minor pre-release on main compares with the last stable tag' => [['v1.3.0', 'v1.4.0-beta1'], 'v1.3.0', 'main', ''];
        yield 'branch merely starting with a number' => [['v1.3.0'], 'v1.3.0', '3.5-hotfix-foo', ''];
        yield 'feature branch named after a major' => [['v1.3.0'], 'v1.3.0', '2.x-feature', ''];
    }

    /**
     * @param non-empty-list<non-empty-string> $tags
     */
    #[DataProvider('blockingCases')]
    public function testRunsTheCheckAgainstTheLatestStableTag(array $tags, string $from, string $refName, string $baseRef): void
    {
        $this->tag(...$tags);

        $output = $this->runGate($refName, $baseRef);

        self::assertStringContainsString(self::STUB_MARKER . ' --from=' . $from . ' ', $output);
        self::assertStringContainsString('--install-development-dependencies', $output);
    }

    /**
     * @return iterable<string, array{non-empty-list<non-empty-string>, non-empty-string, string}>
     */
    public static function newMajorCases(): iterable
    {
        yield 'push to a next-major branch' => [['v1.3.0'], '2.x', ''];
        yield 'push to a release candidate branch' => [['v1.3.0'], '2.0', ''];
        yield 'push to a prefixed release branch' => [['v1.3.0'], 'release/2.0', ''];
        yield 'pull request into a next-major branch' => [['v1.3.0'], 'feature/x', '2.x'];
        yield 'next-major pre-release is the latest tag' => [['v1.3.0', 'v2.0.0-rc1'], '2.x', ''];
        yield 'next-major branch merged into main after its first pre-release' => [['v1.3.0', 'v2.0.0-rc1'], '42/merge', 'main'];
    }

    /**
     * @param non-empty-list<non-empty-string> $tags
     */
    #[DataProvider('newMajorCases')]
    public function testSkipsWhileANewMajorIsPrepared(array $tags, string $refName, string $baseRef): void
    {
        $this->tag(...$tags);

        $output = $this->runGate($refName, $baseRef);

        self::assertStringNotContainsString(self::STUB_MARKER, $output);
        self::assertStringContainsString('BC check skipped', $output);
    }

    public function testATagPushIsComparedWithThePreviousStableTag(): void
    {
        $this->tag('v1.3.0', 'v1.4.0-rc1', 'v1.4.0');

        $output = $this->runGate('v1.4.0', '', 'tag');

        self::assertStringContainsString(self::STUB_MARKER . ' --from=v1.3.0 ', $output);
    }

    public function testATwoPartPreReleaseTagOfANewMajorSkipsTheCheck(): void
    {
        $this->tag('v1.3.0', 'v2.0-rc1');

        $output = $this->runGate('main', '');

        self::assertStringNotContainsString(self::STUB_MARKER, $output);
        self::assertStringContainsString('BC check skipped', $output);
    }

    /**
     * @return iterable<string, array{list<non-empty-string>}>
     */
    public static function withoutStableTag(): iterable
    {
        yield 'no tag' => [[]];
        yield 'only a pre-release' => [['v1.0.0-rc1']];
    }

    /**
     * @param list<non-empty-string> $tags
     */
    #[DataProvider('withoutStableTag')]
    public function testSkipsWithoutAStableReleaseTag(array $tags): void
    {
        $this->tag(...$tags);

        $output = $this->runGate('main', '');

        self::assertStringNotContainsString(self::STUB_MARKER, $output);
        self::assertStringContainsString('No stable release tag yet', $output);
    }

    /**
     * Each tag on its own commit, in order, so the last one is the nearest.
     */
    private function tag(string ...$tags): void
    {
        foreach ($tags as $tag) {
            $this->git('commit', '--quiet', '--allow-empty', '-m', 'release ' . $tag);
            $this->git('tag', $tag);
        }
    }

    private function runGate(string $refName, string $baseRef, string $refType = 'branch'): string
    {
        $process = proc_open(
            ['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $this->gateScript()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repository,
            ['PATH' => self::path(), 'GITHUB_REF_NAME' => $refName, 'GITHUB_BASE_REF' => $baseRef, 'GITHUB_REF_TYPE' => $refType],
        );
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $output);

        return $output;
    }

    private static function path(): string
    {
        $path = getenv('PATH');

        return is_string($path) && $path !== '' ? $path : '/usr/bin:/bin';
    }

    private function gateScript(): string
    {
        foreach (self::workflowMap('jobs', 'bc-check', 'steps') as $step) {
            if (is_array($step) && ($step['name'] ?? null) === 'Roave BC check' && is_string($step['run'] ?? null)) {
                return $step['run'];
            }
        }

        self::fail('php-bundle.yml has no "Roave BC check" step');
    }

    private function git(string ...$arguments): void
    {
        $command = array_merge(
            ['git', '-C', $this->repository, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'tag.gpgSign=false', '-c', 'commit.gpgSign=false'],
            array_values($arguments),
        );
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $output);
    }
}
