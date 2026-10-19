<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PhpstanTemplateTest extends TestCase
{
    private const string TEMPLATES_DIR = __DIR__ . '/../../templates';

    private const string OVERRIDE_CHECK = "    checkMissingOverrideMethodAttribute: true\n";

    public function testRequiresOverrideAttributesExceptUnderPhp74(): void
    {
        self::assertStringContainsString(self::OVERRIDE_CHECK, (string) file_get_contents(self::TEMPLATES_DIR . '/phpstan.dist.neon'));
        self::assertStringContainsString(self::OVERRIDE_CHECK, (string) file_get_contents(self::TEMPLATES_DIR . '/php84/phpstan.dist.neon'));
        self::assertStringNotContainsString('checkMissingOverrideMethodAttribute', (string) file_get_contents(self::TEMPLATES_DIR . '/php74/phpstan.dist.neon'));
    }

    public function testNoRectorTemplateAddsOverrideAttributes(): void
    {
        // Rector drops the PHP 8.3 rule under withPhpVersion(PHP_82) and skips interface methods: PHPStan enforces it.
        foreach (['rector.php', 'php84/rector.php', 'php74/rector.php'] as $file) {
            self::assertStringNotContainsString('AddOverrideAttribute', (string) file_get_contents(self::TEMPLATES_DIR . '/' . $file), $file);
        }
    }
}
