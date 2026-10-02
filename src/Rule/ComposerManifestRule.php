<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use FilesystemIterator;
use JsonException;
use Msstc4Symfony\BundleStandard\Violation;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class ComposerManifestRule implements RuleInterface
{
    private const string FILE = 'composer.json';

    private const string VENDOR = 'msstc4symfony';

    private const string AUTHOR_NAME = 'Maxim Shamaev';

    private const string AUTHOR_EMAIL = 'maxim.shamaev@gmail.com';

    private const string PHP_CONSTRAINT = '>=8.4';

    private const string SYMFONY_CONSTRAINT = '^6.4|^7.0|^8.0';

    /**
     * Packages published under the symfony/ vendor that are not part of the
     * symfony/symfony monorepo and therefore do not follow its lockstep
     * versioning (symfony/monolog-bundle ships its own v3.x line).
     *
     * @var list<non-empty-string>
     */
    private const array NON_LOCKSTEP_SYMFONY_PACKAGES = ['symfony/monolog-bundle'];

    /**
     * Contracts follow their own major line (^2, ^3) independent of the components.
     */
    private const string CONTRACTS_PACKAGE_PATTERN = '#^symfony/([a-z0-9-]+-)?contracts$#';

    /**
     * One alternative of a constraint that cannot leave a single major: ^3, ~3.1, 3.4.1, 3.*, 3.4.*.
     */
    private const string SINGLE_MAJOR_ALTERNATIVE = '/^(?:[\^~]v?\d+(?:\.\d+){0,2}|v?\d+(?:\.\d+){0,2}|v?\d+(?:\.\d+)?\.\*)$/';

    #[Override]
    public function check(string $bundlePath): array
    {
        $target = $bundlePath . '/' . self::FILE;

        if (!is_file($target)) {
            return [new Violation(self::FILE, 'is missing')];
        }

        $raw = file_get_contents($target);

        if ($raw === false) {
            return [new Violation(self::FILE, 'could not be read')];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [new Violation(self::FILE, 'contains invalid JSON: ' . $exception->getMessage())];
        }

        if (!is_array($decoded)) {
            return [new Violation(self::FILE, 'must decode to a JSON object')];
        }

        /** @var array<string, mixed> $manifest */
        $manifest = $decoded;

        return [
            ...$this->checkVersionField($manifest),
            ...$this->checkVendor($manifest),
            ...$this->checkLicense($manifest),
            ...$this->checkAuthor($manifest),
            ...$this->checkPhpConstraint($manifest),
            ...$this->checkDevAutoload($manifest),
            ...$this->checkSymfonyConstraints($manifest),
            ...$this->checkConflict($manifest),
            ...$this->checkExtraSymfonyRequire($manifest),
            ...$this->checkYamlLoaderDependency($bundlePath, $manifest),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<Violation>
     */
    private function checkVersionField(array $manifest): array
    {
        if (!array_key_exists('version', $manifest)) {
            return [];
        }

        return [new Violation(
            self::FILE,
            'must not declare a "version" field; versions come from git tags',
        )];
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<Violation>
     */
    private function checkVendor(array $manifest): array
    {
        $name = $manifest['name'] ?? '';

        if (!is_string($name) || $name === '') {
            return [new Violation(self::FILE, 'must declare a package "name"')];
        }

        $vendor = strstr($name, '/', true);

        if ($vendor === self::VENDOR) {
            return [];
        }

        return [new Violation(
            self::FILE,
            sprintf('must live under the "%s" vendor, found "%s"', self::VENDOR, (string) $vendor),
        )];
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<Violation>
     */
    private function checkLicense(array $manifest): array
    {
        $license = $manifest['license'] ?? null;

        if ($license === 'MIT') {
            return [];
        }

        return [new Violation(
            self::FILE,
            sprintf('must be licensed MIT, found "%s"', is_string($license) ? $license : 'none'),
        )];
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<Violation>
     */
    private function checkAuthor(array $manifest): array
    {
        $authors = $manifest['authors'] ?? [];

        foreach (is_array($authors) ? $authors : [] as $author) {
            if (
                is_array($author)
                && ($author['name'] ?? null) === self::AUTHOR_NAME
                && ($author['email'] ?? null) === self::AUTHOR_EMAIL
            ) {
                return [];
            }
        }

        return [new Violation(
            self::FILE,
            sprintf('must list author "%s <%s>"', self::AUTHOR_NAME, self::AUTHOR_EMAIL),
        )];
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<Violation>
     */
    private function checkPhpConstraint(array $manifest): array
    {
        $require = $manifest['require'] ?? [];
        $php = is_array($require) ? ($require['php'] ?? null) : null;

        if ($php === self::PHP_CONSTRAINT) {
            return [];
        }

        return [new Violation(
            self::FILE,
            sprintf('must require php "%s", found "%s"', self::PHP_CONSTRAINT, is_string($php) ? $php : 'none'),
        )];
    }

    /**
     * Guards against the copy/paste defect where a bundle inherits another bundle's
     * test namespace — autoload-dev entries must extend the package root namespace.
     *
     * @param array<string, mixed> $manifest
     *
     * @return list<Violation>
     */
    private function checkDevAutoload(array $manifest): array
    {
        $rootNamespace = $this->rootNamespace($manifest);

        if ($rootNamespace === null) {
            return [new Violation(self::FILE, 'must declare an autoload.psr-4 root namespace')];
        }

        $autoloadDev = $manifest['autoload-dev'] ?? [];
        $psr4 = is_array($autoloadDev) ? ($autoloadDev['psr-4'] ?? []) : [];

        if (!is_array($psr4)) {
            return [];
        }

        $violations = [];

        foreach (array_keys($psr4) as $namespace) {
            if (!is_string($namespace) || str_starts_with($namespace, $rootNamespace)) {
                continue;
            }

            $violations[] = new Violation(
                self::FILE,
                sprintf(
                    'autoload-dev namespace "%s" does not share the package root namespace "%s"',
                    $namespace,
                    $rootNamespace,
                ),
            );
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<Violation>
     */
    private function checkSymfonyConstraints(array $manifest): array
    {
        $require = $manifest['require'] ?? [];

        if (!is_array($require)) {
            return [];
        }

        $violations = [];

        foreach ($require as $package => $constraint) {
            if (!is_string($package) || !str_starts_with($package, 'symfony/')) {
                continue;
            }

            if (in_array($package, self::NON_LOCKSTEP_SYMFONY_PACKAGES, true)) {
                continue;
            }

            if (preg_match(self::CONTRACTS_PACKAGE_PATTERN, $package) === 1) {
                $violations = [
                    ...$violations,
                    ...$this->checkContractsConstraint($package, is_string($constraint) ? $constraint : null),
                ];

                continue;
            }

            if ($constraint === self::SYMFONY_CONSTRAINT) {
                continue;
            }

            $violations[] = new Violation(
                self::FILE,
                sprintf(
                    'require.%s must use constraint "%s", found "%s"',
                    $package,
                    self::SYMFONY_CONSTRAINT,
                    is_string($constraint) ? $constraint : 'none',
                ),
            );
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private function checkContractsConstraint(string $package, ?string $constraint): array
    {
        if ($constraint !== null && $this->boundsEveryAlternative($constraint)) {
            return [];
        }

        return [new Violation(
            self::FILE,
            sprintf(
                'require.%s must bound each alternative to one major (^, ~ or an exact version), found "%s"',
                $package,
                $constraint ?? 'none',
            ),
        )];
    }

    private function boundsEveryAlternative(string $constraint): bool
    {
        $alternatives = preg_split('/\s*\|\|?\s*/', trim($constraint));

        if ($alternatives === false) {
            return false;
        }

        foreach ($alternatives as $alternative) {
            if (preg_match(self::SINGLE_MAJOR_ALTERNATIVE, $alternative) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<Violation>
     */
    private function checkConflict(array $manifest): array
    {
        $conflict = $manifest['conflict'] ?? [];
        $symfonyConflict = is_array($conflict) ? ($conflict['symfony/symfony'] ?? null) : null;

        if ($symfonyConflict === '*') {
            return [];
        }

        return [new Violation(self::FILE, 'must declare conflict.symfony/symfony = "*"')];
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<Violation>
     */
    private function checkExtraSymfonyRequire(array $manifest): array
    {
        $extra = $manifest['extra'] ?? [];
        $symfonyExtra = is_array($extra) ? ($extra['symfony'] ?? []) : [];

        if (is_array($symfonyExtra) && array_key_exists('require', $symfonyExtra)) {
            return [];
        }

        return [new Violation(self::FILE, 'must declare extra.symfony.require')];
    }

    /**
     * A bundle that loads its service config through YamlFileLoader crashes at container
     * build time in any application that does not happen to pull symfony/yaml transitively.
     *
     * @param array<string, mixed> $manifest
     *
     * @return list<Violation>
     */
    private function checkYamlLoaderDependency(string $bundlePath, array $manifest): array
    {
        $require = $manifest['require'] ?? [];

        if (is_array($require) && array_key_exists('symfony/yaml', $require)) {
            return [];
        }

        if (!$this->sourceUses($bundlePath . '/src', 'YamlFileLoader')) {
            return [];
        }

        return [new Violation(self::FILE, 'must require symfony/yaml: src/ loads configuration with YamlFileLoader')];
    }

    private function sourceUses(string $directory, string $needle): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            if ($content !== false && str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function rootNamespace(array $manifest): ?string
    {
        $autoload = $manifest['autoload'] ?? [];
        $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? []) : [];

        if (!is_array($psr4)) {
            return null;
        }

        foreach (array_keys($psr4) as $namespace) {
            if (is_string($namespace) && $namespace !== '') {
                return $namespace;
            }
        }

        return null;
    }
}
