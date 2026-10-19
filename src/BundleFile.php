<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard;

use UnexpectedValueException;

final readonly class BundleFile
{
    /**
     * An unreadable file must fail the run like an unreadable directory does, not pass as empty.
     *
     * @throws UnexpectedValueException
     */
    public static function read(string $bundlePath, string $relativePath): string
    {
        $code = @file_get_contents($bundlePath . '/' . $relativePath);
        if ($code === false) {
            throw new UnexpectedValueException('cannot read ' . $relativePath);
        }

        return $code;
    }
}
