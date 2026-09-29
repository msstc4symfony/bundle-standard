<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use Msstc4Symfony\BundleStandard\Violation;
use Override;

final readonly class ExactFileRule implements RuleInterface
{
    use MissingFileViolationTrait;

    /**
     * @param non-empty-string $relativePath
     * @param non-empty-string $templatePath
     */
    public function __construct(
        private string $relativePath,
        private string $templatePath,
    ) {
    }

    #[Override]
    public function check(string $bundlePath): array
    {
        $missing = $this->missingFileViolation($bundlePath, $this->relativePath);

        if ($missing !== []) {
            return $missing;
        }

        $target = $bundlePath . '/' . $this->relativePath;

        if (file_get_contents($target) === file_get_contents($this->templatePath)) {
            return [];
        }

        return [new Violation(
            $this->relativePath,
            'differs from the standard template; run `diff` against bundle-standard/templates',
        )];
    }
}
