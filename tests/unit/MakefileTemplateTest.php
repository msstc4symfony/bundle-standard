<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MakefileTemplateTest extends TestCase
{
    private const string MAKEFILE = __DIR__ . '/../../templates/Makefile';

    public function testEveryTargetIsPhony(): void
    {
        $makefile = $this->read();
        preg_match_all('/^([a-zA-Z_0-9-]+):/m', $makefile, $targets);
        preg_match_all('/^\.PHONY:(.*)$/m', $makefile, $phony);

        $declared = preg_split('/\s+/', implode(' ', $phony[1]), -1, \PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($declared);

        $expected = $targets[1];
        sort($expected);
        sort($declared);
        self::assertSame($expected, $declared);
    }

    public function testEveryTargetHasAHelpHint(): void
    {
        $makefile = $this->read();
        preg_match_all('/^([a-zA-Z_0-9-]+):/m', $makefile, $targets);
        preg_match_all('/^([a-zA-Z_0-9-]+):.*?## \S/m', $makefile, $hinted);

        self::assertNotSame([], $targets[1]);
        self::assertSame($targets[1], $hinted[1]);
    }

    private function read(): string
    {
        $makefile = file_get_contents(self::MAKEFILE);
        self::assertIsString($makefile, 'templates/Makefile must be readable');

        return $makefile;
    }
}
