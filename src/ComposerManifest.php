<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard;

use JsonException;

/**
 * Reads a bundle's composer.json for rules that only need its data; ComposerManifestRule reports why it cannot be read.
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
}
