<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard\Test\Unit;

use MaxShamaev\BundleStandard\Rule\RuleInterface;
use MaxShamaev\BundleStandard\StandardDefinition;
use MaxShamaev\BundleStandard\Verifier;
use MaxShamaev\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandardDefinition::class)]
final class StandardDefinitionTest extends TestCase
{
    private const string TEMPLATES_DIR = __DIR__ . '/../../templates';

    public function testProducesOnlyRuleInstances(): void
    {
        $rules = StandardDefinition::rules(self::TEMPLATES_DIR);

        self::assertNotEmpty($rules);

        foreach ($rules as $rule) {
            self::assertInstanceOf(RuleInterface::class, $rule);
        }
    }

    public function testReportsEverythingMissingOnAnEmptyDirectory(): void
    {
        $emptyDir = sys_get_temp_dir() . '/empty-bundle-' . uniqid('', true);
        mkdir($emptyDir);

        try {
            $violations = (new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR)))->verify($emptyDir);

            $files = array_map(static fn (Violation $v): string => $v->file, $violations);

            self::assertContains('Makefile', $files);
            self::assertContains('composer.json', $files);
            self::assertContains('deptrac.yaml', $files);
            self::assertContains('infection.json5', $files);
        } finally {
            rmdir($emptyDir);
        }
    }

    public function testAcceptsTheReferenceBundle(): void
    {
        $reference = __DIR__ . '/../../../healthcheck-bundle';

        if (!is_dir($reference)) {
            self::markTestSkipped('healthcheck-bundle checkout is not available next to bundle-standard');
        }

        $violations = (new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR)))->verify($reference);

        self::assertSame(
            [],
            array_map(static fn (Violation $v): string => $v->format(), $violations),
        );
    }
}
