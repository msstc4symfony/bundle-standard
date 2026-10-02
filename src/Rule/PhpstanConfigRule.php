<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use Msstc4Symfony\BundleStandard\Violation;
use Override;

/**
 * Byte-for-byte template match, except that a bundle may raise the PHPStan level above the template's floor.
 */
final readonly class PhpstanConfigRule implements RuleInterface
{
    use MissingFileViolationTrait;

    private const string LEVEL_LINE = '/^(\h*level:\h*)(\S*)\h*(?:#.*)?$/m';

    /**
     * The template's floor and everything stricter that PHPStan 2 knows.
     *
     * @var list<non-empty-string>
     */
    private const array ACCEPTED_LEVELS = ['9', '10', 'max'];

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

        $config = file_get_contents($bundlePath . '/' . $this->relativePath);
        $template = file_get_contents($this->templatePath);

        if ($config === false || $template === false) {
            return [new Violation($this->relativePath, 'could not be read')];
        }

        if (preg_match(self::LEVEL_LINE, $config, $match) !== 1) {
            return [new Violation($this->relativePath, 'must declare a PHPStan level')];
        }

        $level = $match[2];

        if (!in_array($level, self::ACCEPTED_LEVELS, true)) {
            return [new Violation(
                $this->relativePath,
                sprintf('PHPStan level must be one of %s, found "%s"', implode(', ', self::ACCEPTED_LEVELS), $level),
            )];
        }

        $configWithoutLevel = $this->withoutLevel($config);
        $templateWithoutLevel = $this->withoutLevel($template);

        if ($configWithoutLevel === null || $templateWithoutLevel === null) {
            return [new Violation($this->relativePath, 'could not be compared with the standard template')];
        }

        if ($configWithoutLevel === $templateWithoutLevel) {
            return [];
        }

        return [new Violation(
            $this->relativePath,
            'differs from the standard template beyond the PHPStan level; run `diff` against bundle-standard/templates',
        )];
    }

    /**
     * Only the first level line is neutralised, so a second one still counts as a difference.
     */
    private function withoutLevel(string $contents): ?string
    {
        return preg_replace(self::LEVEL_LINE, '${1}', $contents, 1);
    }
}
