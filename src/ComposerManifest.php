<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard;

use JsonException;

/**
 * Reads a bundle's composer.json for rules that only need its data; ComposerManifestRule reports why it cannot be read.
 *
 * A decoded JSON value. PHPStan has no recursive aliases, so the items of an array stay mixed.
 *
 * @phpstan-type JsonValue array<array-key, mixed>|string|int|float|bool|null
 */
final readonly class ComposerManifest
{
    /**
     * @return array<array-key, mixed>|null null when the file is missing, unreadable or not a JSON object
     */
    public static function read(string $bundlePath): ?array
    {
        $file = $bundlePath . '/composer.json';
        $raw = is_file($file) ? file_get_contents($file) : false;

        if ($raw === false) {
            return null;
        }

        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * One key of extra.bundle-standard, null when absent or composer.json cannot be read.
     *
     * @param non-empty-string $key
     *
     * @return JsonValue
     */
    public static function standardSetting(string $bundlePath, string $key): array|bool|float|int|string|null
    {
        $extra = self::read($bundlePath)['extra'] ?? null;
        $standard = is_array($extra) ? ($extra['bundle-standard'] ?? null) : null;
        $value = is_array($standard) ? ($standard[$key] ?? null) : null;

        return is_array($value) || is_scalar($value) ? $value : null;
    }
}
