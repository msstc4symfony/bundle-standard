<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard\Rule;

use MaxShamaev\BundleStandard\Violation;
use Override;

final readonly class FileExistsRule implements RuleInterface
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
        if (is_file($bundlePath . '/' . $this->relativePath)) {
            return [];
        }

        return [new Violation(
            $this->relativePath,
            'is required by the standard: ' . $this->reason,
        )];
    }
}
