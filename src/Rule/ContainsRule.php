<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use Msstc4Symfony\BundleStandard\Violation;
use Override;

final readonly class ContainsRule implements RuleInterface
{
    use MissingFileViolationTrait;

    /**
     * @param non-empty-string $relativePath
     * @param list<non-empty-string> $requiredSubstrings
     */
    public function __construct(
        private string $relativePath,
        private array $requiredSubstrings,
    ) {
    }

    #[Override]
    public function check(string $bundlePath): array
    {
        $missingFile = $this->missingFileViolation($bundlePath, $this->relativePath);

        if ($missingFile !== []) {
            return $missingFile;
        }

        $target = $bundlePath . '/' . $this->relativePath;
        $content = (string) file_get_contents($target);
        $missing = [];

        foreach ($this->requiredSubstrings as $substring) {
            if (!str_contains($content, $substring)) {
                $missing[] = $substring;
            }
        }

        if ($missing === []) {
            return [];
        }

        return [new Violation(
            $this->relativePath,
            'is missing required settings: ' . implode(', ', $missing),
        )];
    }
}
