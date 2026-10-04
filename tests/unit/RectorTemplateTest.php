<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Msstc4Symfony\BundleStandard\RuntimeProfile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RectorTemplateTest extends TestCase
{
    private const string TEMPLATES_DIR = __DIR__ . '/../../templates';

    public function testSymfonyRulesTargetTheLowestSymfonyTheProfileAllows(): void
    {
        foreach (RuntimeProfile::cases() as $profile) {
            $template = (string) file_get_contents($profile->template(self::TEMPLATES_DIR, 'rector.php'));
            if (preg_match("/^\\\$lowestSymfony = '(\\d+)\\.(\\d+)\\.\\d+';$/m", $template, $floor) !== 1) {
                self::fail($profile->value . ' rector.php must declare the Symfony floor as $lowestSymfony = \'X.Y.Z\';');
            }

            self::assertSame(
                \sprintf('^%s.%s', $floor[1], $floor[2]),
                explode('|', $profile->symfonyConstraint())[0],
                $profile->value,
            );
        }
    }
}
