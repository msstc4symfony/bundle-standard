<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use Msstc4Symfony\BundleStandard\BundleClass;
use Msstc4Symfony\BundleStandard\PackageKind;
use Msstc4Symfony\BundleStandard\StandardTemplateException;
use Msstc4Symfony\BundleStandard\Violation;
use Override;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * deptrac.yaml declares the canonical layers; a bundle may only narrow the canonical ruleset. A bundle's template has
 * a BundleRoot layer whose value is the placeholder, a library's template has neither.
 *
 * A node of parsed YAML: mappings and lists hold nodes of the same kind.
 *
 * @phpstan-type YamlValue array<array-key, mixed>|string|int|float|bool|null
 */
final readonly class DeptracConfigRule implements RuleInterface
{
    public const string BUNDLE_ROOT_PLACEHOLDER = '%BUNDLE_CLASS_PATTERN%';

    private const string FILE = 'deptrac.yaml';

    private const array ALLOWED_KEYS = ['paths', 'layers', 'ruleset'];

    private const string BUNDLE_ROOT_LAYER = 'BundleRoot';

    /**
     * @param non-empty-string $templateFile
     */
    public function __construct(
        private string $templateFile,
        private PackageKind $kind = PackageKind::Bundle,
    ) {
    }

    #[Override]
    public function check(string $bundlePath): array
    {
        // The template is checked first so a broken standard never passes unnoticed.
        [$layers, $ruleset] = $this->canonical();
        $template = 'bundle-standard templates/' . basename($this->templateFile);

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
            ...$this->checkLayers($bundlePath, $config, $layers, $template),
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
        $layers = self::nodeList(is_array($config) ? ($config['layers'] ?? null) : null);
        $rules = is_array($config) ? ($config['ruleset'] ?? null) : null;
        if ($layers === null || !is_array($rules)) {
            throw new StandardTemplateException(\sprintf('Template "%s" has no deptrac.layers list or deptrac.ruleset.', $this->templateFile));
        }

        $this->checkTemplateKind($layers);

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
    private function checkLayers(string $bundlePath, array $config, array $canonical, string $template): array
    {
        if ($this->kind === PackageKind::Library) {
            return self::compareLayers($config, $canonical, null, $template, '');
        }

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
        $hint = ', with the BundleRoot value ' . ($pattern ?? 'set to the bundle class');
        $expected = $canonical;
        array_walk_recursive($expected, static function (mixed &$value) use ($pattern): void {
            if ($value === self::BUNDLE_ROOT_PLACEHOLDER && $pattern !== null) {
                $value = $pattern;
            }
        });

        return [...$violations, ...self::compareLayers($config, $expected, $pattern, $template, $hint)];
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<YamlValue> $expected
     * @param non-empty-string|null $pattern the BundleRoot value; null compares BundleRoot without its value
     *
     * @return list<Violation>
     */
    private static function compareLayers(
        array $config,
        array $expected,
        ?string $pattern,
        string $template,
        string $hint,
    ): array {
        $declared = $config['layers'] ?? null;
        $actual = self::nodeList($declared);
        if ($actual === null || \count($actual) !== \count($expected)) {
            return [new Violation(self::FILE, \sprintf(
                'deptrac.layers must declare the %d canonical layers of %s, found %d: copy them as-is%s',
                \count($expected),
                $template,
                \is_array($declared) ? \count($declared) : 0,
                $hint,
            ))];
        }

        foreach ($expected as $index => $layer) {
            $declared = $pattern === null ? self::withoutBundleRootValue($actual[$index]) : $actual[$index];
            if (self::sorted($declared) !== self::sorted($layer)) {
                return [new Violation(self::FILE, \sprintf(
                    'deptrac.layers[%d] (%s) differs from %s: copy the canonical layers as-is%s',
                    $index,
                    \is_array($layer) && \is_string($layer['name'] ?? null) ? $layer['name'] : '?',
                    $template,
                    $hint,
                ))];
            }
        }

        return [];
    }

    /**
     * A template of the other kind would compare a bundle without its BundleRoot layer, or a library against one.
     *
     * @param list<YamlValue> $layers
     *
     * @throws StandardTemplateException
     */
    private function checkTemplateKind(array $layers): void
    {
        $hasLayer = false;
        foreach ($layers as $layer) {
            $hasLayer = $hasLayer || (\is_array($layer) && ($layer['name'] ?? null) === self::BUNDLE_ROOT_LAYER);
        }

        $hasPlaceholder = false;
        array_walk_recursive($layers, static function (mixed $value) use (&$hasPlaceholder): void {
            $hasPlaceholder = $hasPlaceholder || $value === self::BUNDLE_ROOT_PLACEHOLDER;
        });

        if ($hasLayer !== $hasPlaceholder) {
            throw new StandardTemplateException(\sprintf('Template "%s" must have both a %s layer and the %s placeholder, or neither.', $this->templateFile, self::BUNDLE_ROOT_LAYER, self::BUNDLE_ROOT_PLACEHOLDER));
        }

        if ($hasLayer !== ($this->kind === PackageKind::Bundle)) {
            throw new StandardTemplateException(\sprintf('Template "%s" is not a %s template: a bundle template has the %s layer, a library template does not.', $this->templateFile, $this->kind->value, self::BUNDLE_ROOT_LAYER));
        }
    }

    /**
     * A YAML list whose items are nodes again; null for anything else.
     *
     * @return list<YamlValue>|null
     */
    private static function nodeList(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }

        $nodes = [];
        foreach ($value as $item) {
            if (!\is_array($item) && !\is_scalar($item) && $item !== null) {
                return null;
            }

            $nodes[] = $item;
        }

        return $nodes;
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
        if (!\is_array($layer) || ($layer['name'] ?? null) !== self::BUNDLE_ROOT_LAYER || !\is_array($layer['collectors'] ?? null)) {
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
