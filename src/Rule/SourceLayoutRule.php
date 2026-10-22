<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use FilesystemIterator;
use Msstc4Symfony\BundleStandard\BundleClass;
use Msstc4Symfony\BundleStandard\BundleFile;
use Msstc4Symfony\BundleStandard\PackageKind;
use Msstc4Symfony\BundleStandard\Violation;
use Override;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * src/ holds only the canonical layer directories, Resources/ and the bundle class: anything else would sit outside
 * every deptrac layer and escape the layer rules. A library has neither the bundle class nor the DependencyInjection
 * layer (templates/deptrac-library.yaml).
 */
final readonly class SourceLayoutRule implements RuleInterface
{
    /** @var list<non-empty-string> */
    public const array LAYER_DIRECTORIES = [
        'Domain', 'Application', 'Infrastructure', 'Presentation', 'Framework', 'DependencyInjection',
    ];

    /** Configuration and templates, never classes. */
    public const string RESOURCES_DIRECTORY = 'Resources';

    /** @var list<non-empty-string> */
    public const array DOMAIN_DIRECTORIES = [
        'Entity', 'Document', 'ValueObject', 'Enum', 'Event', 'Repository', 'DocumentRepository',
        'Service', 'Helper', 'Specification',
    ];

    private const string BUNDLE_LAYER_DIRECTORY = 'DependencyInjection';

    private const string MISSING_BUNDLE_SRC = 'is missing: the bundle class and its layers live there';

    private const string MISSING_LIBRARY_SRC = "is missing: the library's layers live there";

    private const string BUNDLE_CLASS_IN_LIBRARY = 'is a bundle class: a library '
        . '(extra.bundle-standard.kind "library") has none, declare kind "bundle" or remove it';

    private const string EXTENSION_LAYER_IN_LIBRARY = 'is the container extension layer: a library has no '
        . 'bundle class, so nothing loads a container extension; declare kind "bundle" or move the code';

    public function __construct(private PackageKind $kind = PackageKind::Bundle)
    {
    }

    #[Override]
    public function check(string $bundlePath): array
    {
        $library = $this->kind === PackageKind::Library;
        $src = $bundlePath . '/src';
        if (!is_dir($src)) {
            return [new Violation('src', $library ? self::MISSING_LIBRARY_SRC : self::MISSING_BUNDLE_SRC)];
        }

        $violations = $library ? self::libraryEntries($src) : self::bundleEntries($src);

        if (is_dir($src . '/Domain')) {
            foreach (self::entries($src . '/Domain') as $entry) {
                if (!is_dir($src . '/Domain/' . $entry) || !\in_array($entry, self::DOMAIN_DIRECTORIES, true)) {
                    $violations[] = new Violation('src/Domain/' . $entry, \sprintf(
                        'is not a Domain or DomainService directory (allowed: %s)',
                        implode(', ', self::DOMAIN_DIRECTORIES),
                    ));
                }
            }
        }

        return [...$violations, ...self::classesUnderResources($src)];
    }

    /**
     * @return list<Violation>
     */
    private static function bundleEntries(string $src): array
    {
        $violations = [];
        $bundleClasses = 0;
        foreach (self::entries($src) as $entry) {
            $path = $src . '/' . $entry;
            if (is_dir($path) && \in_array($entry, [...self::LAYER_DIRECTORIES, self::RESOURCES_DIRECTORY], true)) {
                continue;
            }

            if (is_file($path) && preg_match(BundleClass::FILE_PATTERN, $entry) === 1) {
                $bundleClasses++;

                continue;
            }

            $violations[] = new Violation('src/' . $entry, \sprintf(
                'is not a canonical layer directory or the bundle class (allowed: %s, %s, <Name>Bundle.php)',
                implode(', ', self::LAYER_DIRECTORIES),
                self::RESOURCES_DIRECTORY,
            ));
        }

        if ($bundleClasses !== 1) {
            $violations[] = new Violation('src', \sprintf('must contain exactly one <Name>Bundle.php, found %d', $bundleClasses));
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private static function libraryEntries(string $src): array
    {
        $layers = array_values(array_diff(self::LAYER_DIRECTORIES, [self::BUNDLE_LAYER_DIRECTORY]));
        $violations = [];
        foreach (self::entries($src) as $entry) {
            $path = $src . '/' . $entry;
            if (is_dir($path) && \in_array($entry, [...$layers, self::RESOURCES_DIRECTORY], true)) {
                continue;
            }

            $violations[] = new Violation('src/' . $entry, match (true) {
                is_dir($path) && $entry === self::BUNDLE_LAYER_DIRECTORY => self::EXTENSION_LAYER_IN_LIBRARY,
                is_file($path) && preg_match(BundleClass::FILE_PATTERN, $entry) === 1 => self::BUNDLE_CLASS_IN_LIBRARY,
                default => \sprintf(
                    'is not a canonical layer directory of a library (allowed: %s, %s)',
                    implode(', ', $layers),
                    self::RESOURCES_DIRECTORY,
                ),
            });
        }

        return $violations;
    }

    /**
     * Sorted entries without dot files (.gitkeep and editor files are not code); dot directories are kept.
     *
     * @return list<non-empty-string>
     */
    private static function entries(string $dir): array
    {
        $entries = scandir($dir);

        return $entries === false
            ? []
            : array_values(array_filter(
                $entries,
                static fn (string $e): bool => $e !== '' && $e !== '.' && $e !== '..' && (!str_starts_with($e, '.') || is_dir($dir . '/' . $e)),
            ));
    }

    /**
     * @return list<Violation>
     */
    private static function classesUnderResources(string $src): array
    {
        $resources = $src . '/' . self::RESOURCES_DIRECTORY;
        if (!is_dir($resources)) {
            return [];
        }

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resources, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'src/' . substr($file->getPathname(), \strlen($src) + 1);
            if (self::declaresType(BundleFile::read(\dirname($src), $relative))) {
                $files[] = $relative;
            }
        }

        sort($files);

        return array_map(
            static fn (string $file): Violation => new Violation($file, 'declares a class outside every layer: move it into a layer directory'),
            $files,
        );
    }

    /**
     * A named class, interface, trait or enum; anonymous classes (config closures may build one) and text are not.
     */
    private static function declaresType(string $code): bool
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        foreach ($tokens as $i => $token) {
            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])
                && !(isset($tokens[$i - 1]) && $tokens[$i - 1]->is(T_DOUBLE_COLON))
                && isset($tokens[$i + 1]) && $tokens[$i + 1]->is(T_STRING)) {
                return true;
            }
        }

        return false;
    }
}
