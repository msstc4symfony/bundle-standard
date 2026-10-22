<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use FilesystemIterator;
use Msstc4Symfony\BundleStandard\BundleFile;
use Msstc4Symfony\BundleStandard\PackageKind;
use Msstc4Symfony\BundleStandard\Violation;
use Override;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every compiler pass lives in src/DependencyInjection/CompilerPass/. Classes are found by tokens, never loaded, so
 * only direct "implements CompilerPassInterface" and "extends <a Symfony ...Pass>" of a class or enum are recognised:
 * a child of the bundle's own abstract pass is not, but that base class itself is. A library has no container
 * extension to register a pass, so every pass in it is reported.
 */
final readonly class CompilerPassLocationRule implements RuleInterface
{
    private const string LOCATION = 'src/DependencyInjection/CompilerPass/';

    private const string PASS_INTERFACE = 'Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface';

    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    private const string LIBRARY_REASON = 'a library has no container extension; declare kind "bundle" or remove it';

    public function __construct(private PackageKind $kind = PackageKind::Bundle)
    {
    }

    #[Override]
    public function check(string $bundlePath): array
    {
        $src = $bundlePath . '/src';
        if (!is_dir($src)) {
            return [];
        }

        $passes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'src/' . substr($file->getPathname(), \strlen($src) + 1);
            if ($this->kind === PackageKind::Bundle && str_starts_with($relative, self::LOCATION)) {
                continue;
            }

            foreach (self::passesIn(BundleFile::read($bundlePath, $relative)) as $class) {
                $passes[] = [$relative, $class];
            }
        }

        usort($passes, static fn (array $a, array $b): int => [$a[0], $a[1] ?? ''] <=> [$b[0], $b[1] ?? '']);

        $reason = $this->kind === PackageKind::Library
            ? self::LIBRARY_REASON
            : 'compiler passes live in ' . self::LOCATION;

        return array_map(
            static fn (array $pass): Violation => new Violation($pass[0], \sprintf(
                'declares %s: %s',
                $pass[1] === null ? 'an anonymous compiler pass' : 'compiler pass ' . $pass[1],
                $reason,
            )),
            $passes,
        );
    }

    /**
     * @return list<string|null> short names of the compiler passes declared in the code, null for an anonymous one
     */
    private static function passesIn(string $code): array
    {
        // Every resolvable reference to a pass interface or class spells "Pass" somewhere in the file.
        if (stripos($code, 'pass') === false) {
            return [];
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]),
        ));

        $namespace = '';
        /** @var array<lowercase-string, string> $imports */
        $imports = [];
        $passes = [];
        // Imports live at the namespace's own brace depth; a "use" deeper down imports a trait or binds a closure.
        $depth = 0;
        $namespaceDepth = 0;
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->is(T_NAMESPACE) && !(isset($tokens[$i + 1]) && $tokens[$i + 1]->is(T_NS_SEPARATOR))) {
                $named = isset($tokens[$i + 1]) && $tokens[$i + 1]->is(self::NAME_TOKENS);
                $namespace = $named ? $tokens[$i + 1]->text : '';
                $braced = isset($tokens[$i + ($named ? 2 : 1)]) && $tokens[$i + ($named ? 2 : 1)]->text === '{';
                $namespaceDepth = $braced ? $depth + 1 : 0;
                $imports = [];
            } elseif ($token->is(T_USE) && $depth === $namespaceDepth && isset($tokens[$i + 1]) && $tokens[$i + 1]->is(self::NAME_TOKENS)) {
                $i = self::readImports($tokens, $i + 1, $imports);
            } elseif ($token->is([T_CLASS, T_ENUM]) && !(isset($tokens[$i - 1]) && $tokens[$i - 1]->is(T_DOUBLE_COLON))
                && self::classIsPass($tokens, $i, $namespace, $imports)) {
                // A named declaration is followed by its name; anything else (modifiers, "(", "implements") is anonymous.
                $passes[] = isset($tokens[$i + 1]) && $tokens[$i + 1]->is(T_STRING) ? $tokens[$i + 1]->text : null;
            }
        }

        return $passes;
    }

    /**
     * Reads "use A\B [as C], D\{E, F as G};" starting at the first name; returns the index of the closing ";".
     *
     * @param list<PhpToken> $tokens
     * @param array<lowercase-string, string> $imports
     */
    private static function readImports(array $tokens, int $i, array &$imports): int
    {
        $prefix = '';
        $count = \count($tokens);
        for (; $i < $count && $tokens[$i]->text !== ';'; $i++) {
            $token = $tokens[$i];
            if ($token->text === '}') {
                $prefix = '';

                continue;
            }

            if (!$token->is(self::NAME_TOKENS)) {
                continue;
            }

            // Group use: the prefix name is followed by "\{" (T_NS_SEPARATOR, "{").
            if (isset($tokens[$i + 1]) && $tokens[$i + 1]->is(T_NS_SEPARATOR)) {
                $prefix = ltrim($token->text, '\\') . '\\';
                $i++;

                continue;
            }

            $name = $prefix . ltrim($token->text, '\\');
            $alias = substr($name, (int) strrpos('\\' . $name, '\\'));
            if (isset($tokens[$i + 1], $tokens[$i + 2]) && $tokens[$i + 1]->is(T_AS)) {
                $alias = $tokens[$i + 2]->text;
                $i += 2;
            }

            $imports[strtolower($alias)] = $name;
        }

        return $i;
    }

    /**
     * Scans the class header up to its body; an anonymous class's constructor arguments may hold braces of their own.
     *
     * @param list<PhpToken> $tokens
     * @param array<lowercase-string, string> $imports
     */
    private static function classIsPass(array $tokens, int $i, string $namespace, array $imports): bool
    {
        $section = null;
        $depth = 0;
        $count = \count($tokens);
        for (++$i; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->text === '(') {
                $depth++;
            } elseif ($token->text === ')') {
                $depth--;
            } elseif ($depth === 0 && $token->text === '{') {
                return false;
            } elseif ($depth === 0 && $token->is([T_EXTENDS, T_IMPLEMENTS])) {
                $section = $token->id;
            } elseif ($depth === 0 && $section !== null && $token->is(self::NAME_TOKENS)) {
                $name = self::resolve($token, $namespace, $imports);
                if ($section === T_IMPLEMENTS ? strcasecmp($name, self::PASS_INTERFACE) === 0 : self::isSymfonyPass($name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Symfony's own passes (AbstractRecursivePass, RegisterListenersPass, ...) all end in "Pass".
     */
    private static function isSymfonyPass(string $class): bool
    {
        return stripos($class, 'Symfony\\') === 0 && strcasecmp(substr($class, -4), 'Pass') === 0;
    }

    /**
     * @param array<lowercase-string, string> $imports
     */
    private static function resolve(PhpToken $name, string $namespace, array $imports): string
    {
        $text = $name->text;
        if ($name->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($text, '\\');
        }

        if ($name->is(T_NAME_RELATIVE)) {
            return $namespace . substr($text, \strlen('namespace'));
        }

        $first = explode('\\', $text, 2)[0];
        if (isset($imports[strtolower($first)])) {
            return $imports[strtolower($first)] . substr($text, \strlen($first));
        }

        return $namespace === '' ? $text : $namespace . '\\' . $text;
    }
}
