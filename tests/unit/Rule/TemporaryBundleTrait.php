<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

trait TemporaryBundleTrait
{
    /** @var non-empty-string */
    private string $bundleDir;

    private function createBundle(): void
    {
        $this->bundleDir = sys_get_temp_dir() . '/bundle-' . uniqid('', true);
        mkdir($this->bundleDir);
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->bundleDir . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $content);
    }

    private function removeBundle(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->bundleDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->bundleDir);
    }
}
