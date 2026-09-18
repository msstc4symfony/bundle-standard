<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard\Test\Unit;

use MaxShamaev\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Violation::class)]
final class ViolationTest extends TestCase
{
    public function testFormatJoinsFileAndMessage(): void
    {
        $violation = new Violation('Makefile', 'does not match the standard template');

        self::assertSame('Makefile: does not match the standard template', $violation->format());
    }

    public function testExposesReadonlyProperties(): void
    {
        $violation = new Violation('psalm.xml', 'must be removed');

        self::assertSame('psalm.xml', $violation->file);
        self::assertSame('must be removed', $violation->message);
    }
}
