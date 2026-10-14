<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The php84 templates differ from the shared ones only in the PHP they target.
 */
#[CoversNothing]
final class Php84TemplatesTest extends TestCase
{
    private const string TEMPLATES_DIR = __DIR__ . '/../../templates';

    public function testPhpstanAnalysesForPhp84(): void
    {
        self::assertSame(
            str_replace("phpVersion:\n        min: 80200\n        max: 80599", 'phpVersion: 80400', $this->read('phpstan.dist.neon')),
            $this->read('php84/phpstan.dist.neon'),
        );
    }

    public function testCsFixerLeavesTheParenthesesOfNewExpressionsToRector(): void
    {
        self::assertSame(
            str_replace("        'new_expression_parentheses' => ['use_parentheses' => true],\n", '', $this->read('.php-cs-fixer.dist.php')),
            $this->read('php84/.php-cs-fixer.dist.php'),
        );
    }

    public function testRectorRaisesTheCodeToPhp84(): void
    {
        $expected = strtr($this->read('rector.php'), [
            "use Rector\\ValueObject\\PhpVersion;\n" => '',
            "        // The package runs on PHP 8.2: no rule may raise the code above it.\n        ->withPhpVersion(PhpVersion::PHP_82)\n        ->withPhpSets(php82: true)\n" => "        ->withPhpSets(php84: true)\n",
        ]);

        self::assertSame($expected, $this->read('php84/rector.php'));
    }

    private function read(string $file): string
    {
        $content = file_get_contents(self::TEMPLATES_DIR . '/' . $file);
        self::assertIsString($content);

        return $content;
    }
}
