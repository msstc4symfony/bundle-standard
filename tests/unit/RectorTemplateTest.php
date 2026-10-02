<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Msstc4Symfony\BundleStandard\Rule\ComposerManifestRule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

#[CoversNothing]
final class RectorTemplateTest extends TestCase
{
    private const string TEMPLATE = __DIR__ . '/../../templates/rector.php';

    public function testSymfonyRulesTargetTheLowestSymfonyTheStandardAllows(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);
        if (preg_match("/^\\\$lowestSymfony = '(\\d+)\\.(\\d+)\\.\\d+';$/m", $template, $floor) !== 1) {
            self::fail('templates/rector.php must declare the Symfony floor as $lowestSymfony = \'X.Y.Z\';');
        }

        $symfonyConstraint = new ReflectionClassConstant(ComposerManifestRule::class, 'SYMFONY_CONSTRAINT')->getValue();
        self::assertIsString($symfonyConstraint);

        self::assertSame(
            \sprintf('^%s.%s', $floor[1], $floor[2]),
            explode('|', $symfonyConstraint)[0],
        );
    }
}
