<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard\Rule;

use MaxShamaev\BundleStandard\Violation;

/**
 * Shared "is missing" guard for rules that need their target file present
 * in the bundle before applying any further, rule-specific checks.
 */
trait MissingFileViolationTrait
{
    /**
     * @param non-empty-string $bundlePath
     * @param non-empty-string $relativePath
     *
     * @return list<Violation>
     */
    private function missingFileViolation(string $bundlePath, string $relativePath): array
    {
        if (is_file($bundlePath . '/' . $relativePath)) {
            return [];
        }

        return [new Violation($relativePath, 'is missing')];
    }
}
