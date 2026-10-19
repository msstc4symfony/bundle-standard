<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard;

final readonly class BundleClass
{
    /** File name of a bundle class directly in src/. */
    public const string FILE_PATTERN = '/^[A-Z]\w*Bundle\.php$/D';

    /**
     * @return non-empty-string|null the file name of the single bundle class in src/, null for none or several
     */
    public static function file(string $bundlePath): ?string
    {
        $src = $bundlePath . '/src';
        $entries = is_dir($src) ? scandir($src) : false;
        if ($entries === false) {
            return null;
        }

        $files = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => preg_match(self::FILE_PATTERN, $entry) === 1 && is_file($src . '/' . $entry),
        ));

        return count($files) === 1 ? $files[0] : null;
    }

    /**
     * FQCN of the single src/<Name>Bundle.php under the PSR-4 prefix that maps to src/.
     *
     * @return non-empty-string|null null when src/ has no or several bundle classes, or no prefix maps to src/
     */
    public static function of(string $bundlePath): ?string
    {
        $file = self::file($bundlePath);
        $prefix = self::srcPrefix($bundlePath);

        return $file === null || $prefix === null ? null : $prefix . basename($file, '.php');
    }

    /**
     * @return non-empty-string|null the PSR-4 prefix mapped to src/, null for none or several
     */
    public static function srcPrefix(string $bundlePath): ?string
    {
        $manifest = ComposerManifest::read($bundlePath);
        $autoload = $manifest['autoload'] ?? null;
        $psr4 = \is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
        if (!\is_array($psr4)) {
            return null;
        }

        $prefixes = [];
        foreach ($psr4 as $prefix => $dirs) {
            foreach (\is_array($dirs) ? $dirs : [$dirs] as $dir) {
                if (\is_string($prefix) && str_ends_with($prefix, '\\') && \is_string($dir) && rtrim((string) preg_replace('#^\./#', '', $dir), '/') === 'src') {
                    $prefixes[$prefix] = true;
                }
            }
        }

        return \count($prefixes) === 1 ? (string) array_key_first($prefixes) : null;
    }
}
