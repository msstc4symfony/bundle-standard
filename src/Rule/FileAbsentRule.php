<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard\Rule;

use MaxShamaev\BundleStandard\Violation;
use Override;

final readonly class FileAbsentRule implements RuleInterface
{
    /**
     * @param non-empty-string $relativePath
     * @param non-empty-string $reason
     */
    public function __construct(
        private string $relativePath,
        private string $reason,
    ) {
    }

    #[Override]
    public function check(string $bundlePath): array
    {
        if (!file_exists($bundlePath . '/' . $this->relativePath)) {
            return [];
        }

        return [new Violation(
            $this->relativePath,
            'must not exist: ' . $this->reason,
        )];
    }
}
