<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard;

/**
 * The PHP a package must run on. Bundles run on 8.2; a package that needs 8.4 declares
 * extra.bundle-standard.runtime: "php84", one that runs inside another tool on older PHP (the DTO generator's
 * Symfony bridge, PHP 7.4) declares "php74".
 */
enum RuntimeProfile: string
{
    case Php82 = 'php82';
    case Php84 = 'php84';
    case Php74 = 'php74';

    /**
     * The profile the bundle declares, Php82 when it declares none, null for a value the standard does not know.
     * An unreadable composer.json counts as no declaration: ComposerManifestRule reports it.
     */
    public static function declaredBy(string $bundlePath): ?self
    {
        $declared = ComposerManifest::standardSetting($bundlePath, 'runtime');

        if ($declared === null) {
            return self::Php82;
        }

        return is_string($declared) ? self::tryFrom($declared) : null;
    }

    /**
     * The declared extra.bundle-standard.runtime as JSON, for messages; null when absent.
     */
    public static function declaration(string $bundlePath): ?string
    {
        $declared = ComposerManifest::standardSetting($bundlePath, 'runtime');

        return $declared === null ? null : json_encode($declared, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return non-empty-string the require.php constraint
     */
    public function phpConstraint(): string
    {
        return match ($this) {
            self::Php82 => '>=8.2',
            self::Php84 => '>=8.4',
            self::Php74 => '>=7.4',
        };
    }

    /**
     * Symfony 6.4 needs PHP 8.1, so under php74 a package that runs on 7.4 may also work with Symfony 5.4.
     *
     * @return non-empty-string the constraint every lockstep symfony/* requirement uses
     */
    public function symfonyConstraint(): string
    {
        return match ($this) {
            self::Php82, self::Php84 => '^7.4|^8.0',
            self::Php74 => '^5.4|^6.4|^7.0|^8.0',
        };
    }

    /**
     * @return non-empty-string the PHP of the workflow's "minimal" job, which installs composer.json alone
     */
    public function minimalPhp(): string
    {
        return match ($this) {
            self::Php82 => '8.2',
            self::Php84 => '8.4',
            self::Php74 => '7.4',
        };
    }

    /**
     * The profile's own template when it has one, else the shared one.
     *
     * @param non-empty-string $templatesDir
     * @param non-empty-string $file
     *
     * @return non-empty-string
     */
    public function template(string $templatesDir, string $file): string
    {
        $own = $templatesDir . '/' . $this->value . '/' . $file;

        return is_file($own) ? $own : $templatesDir . '/' . $file;
    }
}
