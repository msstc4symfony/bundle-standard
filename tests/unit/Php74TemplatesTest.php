<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The php74 templates differ from the shared ones only where PHP 7.4 or PHPUnit 9.6 need it.
 */
#[CoversNothing]
final class Php74TemplatesTest extends TestCase
{
    private const string TEMPLATES_DIR = __DIR__ . '/../../templates';

    public function testPhpstanAnalysesForPhp74(): void
    {
        self::assertSame(
            str_replace('phpVersion: 80400', 'phpVersion: 70400', $this->read('phpstan.dist.neon')),
            $this->read('php74/phpstan.dist.neon'),
        );
    }

    public function testCsFixerAddsNoTrailingCommaToParameters(): void
    {
        self::assertSame(
            str_replace("'elements' => ['arguments', 'arrays', 'match', 'parameters'],", "'elements' => ['arguments', 'arrays'],", $this->read('.php-cs-fixer.dist.php')),
            $this->read('php74/.php-cs-fixer.dist.php'),
        );
    }

    public function testRectorKeepsTheCodeOnPhp74AndSymfony54(): void
    {
        $expected = strtr($this->read('rector.php'), [
            "use Rector\\Config\\RectorConfig;\n" => "use Rector\\Config\\RectorConfig;\nuse Rector\\ValueObject\\PhpVersion;\n",
            "\$lowestSymfony = '7.4.0';" => "\$lowestSymfony = '5.4.0';",
            'of the standard\'s "^7.4|^8.0" constraint.' => 'of the php74 profile\'s "^5.4|^6.4|^7.0|^8.0" constraint.',
            "        ->withPhpSets(php84: true)\n" => "        // The package runs on PHP 7.4: no rule may raise the code above it.\n        ->withPhpVersion(PhpVersion::PHP_74)\n        ->withPhpSets(php74: true)\n",
            "        ->withAttributesSets(symfony: true, doctrine: true, mongoDb: true, phpunit: true)\n" => '',
        ]);

        self::assertSame($expected, $this->read('php74/rector.php'));
    }

    public function testPhpunitConfigIsForPhpunit96WithTheSharedSuites(): void
    {
        $config = $this->read('php74/phpunit.xml.dist');
        $shared = $this->read('phpunit.xml.dist');

        self::assertStringContainsString('cacheResultFile=".phpunit.cache/test-results"', $config);
        self::assertStringNotContainsString('<source', $config);
        foreach (['testsuites', 'php'] as $block) {
            self::assertSame($this->block($shared, $block), $this->block($config, $block), $block);
        }
    }

    private function block(string $xml, string $element): string
    {
        if (preg_match(sprintf('~<%1$s>.*?</%1$s>~s', $element), $xml, $match) !== 1) {
            self::fail(sprintf('No <%s> block', $element));
        }

        return $match[0];
    }

    private function read(string $file): string
    {
        $content = file_get_contents(self::TEMPLATES_DIR . '/' . $file);
        self::assertIsString($content);

        return $content;
    }
}
