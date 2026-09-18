<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard\Test\Unit;

use InvalidArgumentException;
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

    public function testRejectsEmptyFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('file');

        // Reason: deliberately violates the non-empty-string param to exercise the runtime guard.
        // @phpstan-ignore argument.type
        new Violation('', 'must be removed');
    }

    public function testRejectsEmptyMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message');

        // Reason: deliberately violates the non-empty-string param to exercise the runtime guard.
        // @phpstan-ignore argument.type
        new Violation('psalm.xml', '');
    }
}
