<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard;

/**
 * What the package ships. A bundle registers itself in a Symfony kernel; a library declares
 * extra.bundle-standard.kind: "library" and keeps the hex layers without the bundle class or the DI layer.
 */
enum PackageKind: string
{
    case Bundle = 'bundle';
    case Library = 'library';

    /**
     * The kind the package declares, Bundle when it declares none, null for a value the standard does not know.
     * An unreadable composer.json counts as no declaration: ComposerManifestRule reports it.
     */
    public static function declaredBy(string $bundlePath): ?self
    {
        $declared = ComposerManifest::standardSetting($bundlePath, 'kind');

        if ($declared === null) {
            return self::Bundle;
        }

        return is_string($declared) ? self::tryFrom($declared) : null;
    }

    /**
     * The declared extra.bundle-standard.kind as JSON, for messages; null when absent.
     */
    public static function declaration(string $bundlePath): ?string
    {
        $declared = ComposerManifest::standardSetting($bundlePath, 'kind');

        return $declared === null ? null : json_encode($declared, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
