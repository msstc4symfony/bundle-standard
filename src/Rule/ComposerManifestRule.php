<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use JsonException;
use Msstc4Symfony\BundleStandard\Violation;
use Override;

final readonly class ComposerManifestRule implements RuleInterface
{
    private const string FILE = 'composer.json';

    private const string VENDOR = 'msstc4symfony';

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
            ...$this->checkPhpConstraint($manifest),
            ...$this->checkDevAutoload($manifest),
            ...$this->checkSymfonyConstraints($manifest),
            ...$this->checkConflict($manifest),
            ...$this->checkExtraSymfonyRequire($manifest),
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
