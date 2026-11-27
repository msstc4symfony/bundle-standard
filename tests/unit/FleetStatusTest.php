<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Override;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;

/**
 * bin/fleet-status against a stub `gh` that answers from fixtures: the latest completed run per workflow decides.
 */
#[RequiresOperatingSystemFamily('Linux')]
final class FleetStatusTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../bin/fleet-status';

    private string $dir;

    #[Override]
    protected function setUp(): void
    {
        if (!self::onPath('jq')) {
            self::markTestSkipped('jq is not installed');
        }
        $this->dir = sys_get_temp_dir() . '/fleet-status-' . uniqid('', true);
        mkdir($this->dir . '/bin', 0o777, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/{bin/,}*', GLOB_BRACE) as $file) {
            if (\is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir . '/bin');
        @rmdir($this->dir);
    }

    public function testTheLatestCompletedPushOrScheduledRunOfEachWorkflowDecides(): void
    {
        $this->stub([
            'alpha' => [
                ['workflowName' => 'CI', 'status' => 'in_progress', 'conclusion' => '', 'event' => 'push', 'url' => 'u0'],
                ['workflowName' => 'CI', 'status' => 'completed', 'conclusion' => 'success', 'event' => 'schedule', 'url' => 'u1'],
                ['workflowName' => 'CI', 'status' => 'completed', 'conclusion' => 'failure', 'event' => 'push', 'url' => 'u2'],
                ['workflowName' => 'Dependabot', 'status' => 'completed', 'conclusion' => 'failure', 'event' => 'dynamic', 'url' => 'u3'],
            ],
            'beta' => [
                ['workflowName' => 'Checks', 'status' => 'completed', 'conclusion' => 'failure', 'event' => 'push', 'url' => 'u4'],
            ],
        ]);

        [$exit, $report] = $this->runFleetStatus();

        self::assertSame(1, $exit);
        self::assertStringContainsString("### CI is red: 1 workflow(s)\n\n- beta / Checks\n", $report);
        self::assertStringContainsString('| alpha | CI | ok | [run](u1) |', $report);
        self::assertStringContainsString('| beta | Checks | **failure** | [run](u4) |', $report);
        self::assertStringNotContainsString('Dependabot', $report);
    }

    public function testAllGreenExitsZero(): void
    {
        $this->stub(['alpha' => [['workflowName' => 'CI', 'status' => 'completed', 'conclusion' => 'success', 'event' => 'push', 'url' => 'u1']]]);

        [$exit, $report] = $this->runFleetStatus();

        self::assertSame(0, $exit);
        self::assertStringStartsWith('### CI is green in every repository of acme', $report);
    }

    /**
     * @param array<string, list<array{workflowName: string, status: string, conclusion: string, event: string, url: string}>> $runs
     */
    private function stub(array $runs): void
    {
        foreach ($runs as $repo => $list) {
            file_put_contents($this->dir . '/runs-' . $repo . '.json', json_encode($list, JSON_THROW_ON_ERROR));
        }
        $repos = implode("\n", array_keys($runs));
        file_put_contents($this->dir . '/bin/gh', <<<SH
            #!/usr/bin/env bash
            case "\$1 \$2" in
              'repo list') printf '%s\\n' "{$repos}" ;;
              'api repos/'*) echo main ;;
              'run list') repo=\${4#acme/}; cat "{$this->dir}/runs-\$repo.json" ;;
            esac
            SH);
        chmod($this->dir . '/bin/gh', 0o755);
    }

    /**
     * @return array{int, string}
     */
    private function runFleetStatus(): array
    {
        $process = proc_open(
            ['bash', self::SCRIPT, 'acme', $this->dir . '/report.md'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            ['PATH' => $this->dir . '/bin' . PATH_SEPARATOR . (string) getenv('PATH')],
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($this->dir . '/report.md')];
    }

    private static function onPath(string $command): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $command)) {
                return true;
            }
        }

        return false;
    }
}
