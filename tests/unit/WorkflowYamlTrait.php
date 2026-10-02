<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

trait WorkflowYamlTrait
{
    /** @var array<mixed>|null */
    private static ?array $workflow = null;

    /**
     * @return array<mixed>|scalar|null
     */
    private static function workflowValue(string ...$path): mixed
    {
        if (self::$workflow === null) {
            $parsed = Yaml::parseFile(__DIR__ . '/../../.github/workflows/php-bundle.yml');
            Assert::assertIsArray($parsed);
            self::$workflow = $parsed;
        }

        $node = self::$workflow;

        foreach ($path as $key) {
            Assert::assertIsArray($node);
            Assert::assertArrayHasKey($key, $node, 'php-bundle.yml has no ' . implode('.', $path));
            $node = $node[$key];
        }

        if ($node !== null && !is_scalar($node) && !is_array($node)) {
            Assert::fail('php-bundle.yml holds a non-plain value at ' . implode('.', $path));
        }

        return $node;
    }

    /**
     * @return array<mixed>
     */
    private static function workflowMap(string ...$path): array
    {
        $node = self::workflowValue(...$path);
        Assert::assertIsArray($node);

        return $node;
    }

    private static function workflowStepRun(string $job, string $needle): string
    {
        foreach (self::workflowMap('jobs', $job, 'steps') as $step) {
            $run = is_array($step) ? ($step['run'] ?? null) : null;

            if (is_string($run) && str_contains($run, $needle)) {
                return $run;
            }
        }

        Assert::fail(sprintf('Job "%s" has no step running "%s"', $job, $needle));
    }
}
