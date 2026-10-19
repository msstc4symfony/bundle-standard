<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use Msstc4Symfony\BundleStandard\BundleClass;
use Msstc4Symfony\BundleStandard\StandardTemplateException;
use Msstc4Symfony\BundleStandard\Violation;
use Override;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * deptrac.yaml declares the canonical layers; a bundle may only narrow the canonical ruleset.
 *
 * A node of parsed YAML: mappings and lists hold nodes of the same kind.
 *
 * @phpstan-type YamlValue array<array-key, mixed>|string|int|float|bool|null
 */
final readonly class DeptracConfigRule implements RuleInterface
{
    public const string BUNDLE_ROOT_PLACEHOLDER = '%BUNDLE_CLASS_PATTERN%';

    private const string FILE = 'deptrac.yaml';

    private const string TEMPLATE = 'bundle-standard templates/deptrac.yaml';

    private const array ALLOWED_KEYS = ['paths', 'layers', 'ruleset'];

    /**
     * @param non-empty-string $templateFile
     */
    public function __construct(
        private string $templateFile,
    ) {
    }

    #[Override]
    public function check(string $bundlePath): array
    {
        // The template is checked first so a broken standard never passes unnoticed.
        [$layers, $ruleset] = $this->canonical();

        $file = $bundlePath . '/' . self::FILE;
        if (!is_file($file)) {
            // FileExistsRule reports the missing file.
            return [];
        }

        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $e) {
            return [new Violation(self::FILE, 'is not valid YAML: ' . $e->getMessage())];
        }

        $config = is_array($parsed) ? ($parsed['deptrac'] ?? null) : null;
        if (!is_array($config)) {
            return [new Violation(self::FILE, 'must have a "deptrac" mapping at the root')];
        }

        return [
            ...$this->checkRootKeys($parsed),
            ...$this->checkKeys($config),
            ...$this->checkPaths($config),
            ...$this->checkLayers($bundlePath, $config, $layers),
            ...$this->checkRuleset($config, $ruleset),
        ];
    }

    /**
     * @return array{list<YamlValue>, array<string, list<string>>}
     *
     * @throws StandardTemplateException
     */
    private function canonical(): array
    {
        try {
            $template = Yaml::parseFile($this->templateFile);
        } catch (ParseException $e) {
            throw new StandardTemplateException(\sprintf('Template "%s" is not valid YAML: %s', $this->templateFile, $e->getMessage()), 0, $e);
        }

        $config = is_array($template) ? ($template['deptrac'] ?? null) : null;
        $layers = is_array($config) ? ($config['layers'] ?? null) : null;
        $rules = is_array($config) ? ($config['ruleset'] ?? null) : null;
        if (!is_array($layers) || !array_is_list($layers) || !is_array($rules)) {
            throw new StandardTemplateException(\sprintf('Template "%s" has no deptrac.layers list or deptrac.ruleset.', $this->templateFile));
        }

        $ruleset = [];
        foreach ($rules as $layer => $dependencies) {
            $ruleset[(string) $layer] = array_values(array_filter(
                \is_array($dependencies) ? $dependencies : [],
                is_string(...),
            ));
        }

        return [$layers, $ruleset];
    }

    /**
     * deptrac loads the file through Symfony DI: "imports" or "services" would merge in rules this check never sees.
     *
     * @param array<array-key, mixed> $parsed
     *
     * @return list<Violation>
     */
    private function checkRootKeys(array $parsed): array
    {
        $violations = [];
        foreach (array_keys($parsed) as $key) {
            if ($key !== 'deptrac') {
                $violations[] = new Violation(self::FILE, \sprintf(
                    'root key "%s" is not allowed: deptrac.yaml holds only the "deptrac" mapping',
                    $key,
                ));
            }
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return list<Violation>
     */
    private function checkKeys(array $config): array
    {
        $violations = [];
        foreach (array_keys($config) as $key) {
            if (!\in_array($key, self::ALLOWED_KEYS, true)) {
                $violations[] = new Violation(self::FILE, \sprintf(
                    'key "deptrac.%s" is not allowed: the layer rules apply to all of src/ without exceptions',
                    $key,
                ));
            }
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return list<Violation>
     */
    private function checkPaths(array $config): array
    {
        return ($config['paths'] ?? null) === ['./src']
            ? []
            : [new Violation(self::FILE, 'deptrac.paths must be ["./src"]')];
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<YamlValue> $canonical
     *
     * @return list<Violation>
     */
    private function checkLayers(string $bundlePath, array $config, array $canonical): array
    {
        // A missing or ambiguous bundle file is SourceLayoutRule's to report; the other layers are still compared.
        $hasBundleFile = BundleClass::file($bundlePath) !== null;
        $bundleClass = $hasBundleFile ? BundleClass::of($bundlePath) : null;
        $violations = $hasBundleFile && $bundleClass === null
            ? [new Violation(
                self::FILE,
                'cannot derive the bundle class for the BundleRoot layer: composer.json needs exactly one autoload.psr-4 prefix for "src/"',
            )]
            : [];

        // classLike collectors take an unanchored, case-insensitive regex: anchor it, escape the separators.
        $pattern = $bundleClass === null ? null : '^' . str_replace('\\', '\\\\', $bundleClass) . '$';
        $hint = $pattern ?? 'set to the bundle class';
        $expected = $canonical;
        array_walk_recursive($expected, static function (mixed &$value) use ($pattern): void {
            if ($value === self::BUNDLE_ROOT_PLACEHOLDER && $pattern !== null) {
                $value = $pattern;
            }
        });

        $actual = $config['layers'] ?? null;
        if (!\is_array($actual) || !array_is_list($actual) || \count($actual) !== \count($expected)) {
            return [...$violations, new Violation(self::FILE, \sprintf(
                'deptrac.layers must declare the %d canonical layers of %s, found %d: copy them as-is, with the BundleRoot value %s',
                \count($expected),
                self::TEMPLATE,
                \is_array($actual) ? \count($actual) : 0,
                $hint,
            ))];
        }

        foreach ($expected as $index => $layer) {
            $declared = $pattern === null ? self::withoutBundleRootValue($actual[$index]) : $actual[$index];
            if (self::sorted($declared) !== self::sorted($layer)) {
                return [...$violations, new Violation(self::FILE, \sprintf(
                    'deptrac.layers[%d] (%s) differs from %s: copy the canonical layers as-is, with the BundleRoot value %s',
                    $index,
                    \is_array($layer) && \is_string($layer['name'] ?? null) ? $layer['name'] : '?',
                    self::TEMPLATE,
                    $hint,
                ))];
            }
        }

        return $violations;
    }

    /**
     * The BundleRoot layer with its collector values replaced by the placeholder, for comparing without a bundle class.
     */
    /**
     * @param YamlValue $layer
     *
     * @return YamlValue
     */
    private static function withoutBundleRootValue(mixed $layer): array|string|int|float|bool|null
    {
        if (!\is_array($layer) || ($layer['name'] ?? null) !== 'BundleRoot' || !\is_array($layer['collectors'] ?? null)) {
            return $layer;
        }

        $layer['collectors'] = array_map(
            static fn (mixed $collector): mixed => \is_array($collector) && \array_key_exists('value', $collector)
                ? ['value' => self::BUNDLE_ROOT_PLACEHOLDER] + $collector
                : $collector,
            $layer['collectors'],
        );

        return $layer;
    }

    /**
     * Mapping keys sorted recursively: YAML key order carries no meaning for deptrac, list order does.
     */
    /**
     * @param YamlValue $value
     *
     * @return YamlValue
     */
    private static function sorted(mixed $value): array|string|int|float|bool|null
    {
        if (!\is_array($value)) {
            return $value;
        }

        // Symfony YAML yields no objects without the object flags, so every child is a node again.
        foreach ($value as $key => $item) {
            $value[$key] = \is_array($item) || \is_scalar($item) ? self::sorted($item) : null;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $config
     * @param array<string, list<string>> $canonical
     *
     * @return list<Violation>
     */
    private function checkRuleset(array $config, array $canonical): array
    {
        $ruleset = $config['ruleset'] ?? [];
        if (!\is_array($ruleset)) {
            return [new Violation(self::FILE, 'deptrac.ruleset must be a mapping of layer names')];
        }

        $violations = [];
        foreach ($ruleset as $layer => $dependencies) {
            $layer = (string) $layer;
            if (!\array_key_exists($layer, $canonical)) {
                $violations[] = new Violation(self::FILE, \sprintf('deptrac.ruleset.%s: unknown layer', $layer));

                continue;
            }

            $dependencies ??= [];
            if (!\is_array($dependencies) || !array_is_list($dependencies) || array_filter($dependencies, is_string(...)) !== $dependencies) {
                $violations[] = new Violation(self::FILE, \sprintf('deptrac.ruleset.%s must be a list of layer names', $layer));

                continue;
            }

            foreach (array_diff($dependencies, $canonical[$layer]) as $extra) {
                $violations[] = new Violation(self::FILE, str_starts_with($extra, '+')
                    ? \sprintf('deptrac.ruleset.%s: transitive dependency "%s" is not allowed, list the layers explicitly', $layer, $extra)
                    : \sprintf(
                        'deptrac.ruleset.%s must not depend on %s (canonical: %s)',
                        $layer,
                        $extra,
                        $canonical[$layer] === [] ? 'nothing' : implode(', ', $canonical[$layer]),
                    ));
            }
        }

        return $violations;
    }
}
