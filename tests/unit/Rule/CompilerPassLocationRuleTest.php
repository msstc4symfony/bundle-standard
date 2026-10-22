<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use Msstc4Symfony\BundleStandard\PackageKind;
use Msstc4Symfony\BundleStandard\Rule\CompilerPassLocationRule;
use Msstc4Symfony\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompilerPassLocationRule::class)]
final class CompilerPassLocationRuleTest extends TestCase
{
    use TemporaryBundleTrait;

    protected function setUp(): void
    {
        $this->createBundle();
    }

    protected function tearDown(): void
    {
        $this->removeBundle();
    }

    public function testAcceptsPassesInTheirLocation(): void
    {
        $this->write('src/DependencyInjection/CompilerPass/Nested/FooPass.php', <<<'PHP'
            <?php
            namespace Acme\DependencyInjection\CompilerPass\Nested;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            final class FooPass implements CompilerPassInterface {}
            PHP);

        self::assertSame([], $this->files());
    }

    public function testReportsAnImportedInterfaceOutsideTheLocation(): void
    {
        $this->write('src/DependencyInjection/Compiler/FooPass.php', <<<'PHP'
            <?php
            namespace Acme\DependencyInjection\Compiler;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            final class FooPass implements \Countable, CompilerPassInterface {}
            PHP);

        $violations = new CompilerPassLocationRule()->check($this->bundleDir);

        self::assertSame(['src/DependencyInjection/Compiler/FooPass.php'], $this->files());
        self::assertSame('declares compiler pass FooPass: compiler passes live in src/DependencyInjection/CompilerPass/', $violations[0]->message);
    }

    public function testReportsAFullyQualifiedAndAQualifiedName(): void
    {
        $this->write('src/A.php', <<<'PHP'
            <?php
            namespace Acme;
            final class A implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {}
            PHP);
        $this->write('src/B.php', <<<'PHP'
            <?php
            namespace Acme;
            use Symfony\Component\DependencyInjection\Compiler;
            final class B implements Compiler\CompilerPassInterface {}
            PHP);

        self::assertSame(['src/A.php', 'src/B.php'], $this->files());
    }

    public function testResolvesAnAliasedInterface(): void
    {
        $this->write('src/DependencyInjection/FooPass.php', <<<'PHP'
            <?php
            namespace Acme\DependencyInjection;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as Pass;
            final class FooPass implements Pass {}
            PHP);

        self::assertSame(['src/DependencyInjection/FooPass.php'], $this->files());
    }

    public function testResolvesAGroupUse(): void
    {
        $this->write('src/DependencyInjection/FooPass.php', <<<'PHP'
            <?php
            namespace Acme\DependencyInjection;
            use Symfony\Component\DependencyInjection\{ContainerBuilder, Compiler\CompilerPassInterface};
            final class FooPass implements CompilerPassInterface {}
            PHP);

        self::assertSame(['src/DependencyInjection/FooPass.php'], $this->files());
    }

    public function testReportsARecursivePassOutsideTheLocation(): void
    {
        $this->write('src/DependencyInjection/FooPass.php', <<<'PHP'
            <?php
            namespace Acme\DependencyInjection;
            use Symfony\Component\DependencyInjection\Compiler\AbstractRecursivePass;
            final class FooPass extends AbstractRecursivePass {}
            PHP);

        self::assertSame(['src/DependencyInjection/FooPass.php'], $this->files());
    }

    public function testIgnoresClassConstantsAndClosureUse(): void
    {
        $this->write('src/AcmeBundle.php', <<<'PHP'
            <?php
            namespace Acme;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            $id = CompilerPassInterface::class;
            $f = function () use ($id): string { return $id; };
            final class AcmeBundle
            {
            }
            PHP);
        $this->write('src/Application/PassAware.php', <<<'PHP'
            <?php
            namespace Acme\Application;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            interface PassAware extends CompilerPassInterface {}
            PHP);
        $this->write('src/Application/Other.php', <<<'PHP'
            <?php
            namespace Acme\Application;
            final class Other implements CompilerPassInterface {}
            PHP);

        self::assertSame([], $this->files());
    }

    public function testLooksPastAClassConstantBeforeTheDeclaration(): void
    {
        $this->write('src/DependencyInjection/FooPass.php', <<<'PHP'
            <?php
            namespace Acme\DependencyInjection;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            const PASS = CompilerPassInterface::class;
            function helper(): void {}
            final class FooPass implements CompilerPassInterface {}
            PHP);

        self::assertSame(['src/DependencyInjection/FooPass.php'], $this->files());
    }

    public function testSkipsNonPhpFilesAndAMissingSrc(): void
    {
        self::assertSame([], $this->files());

        $this->write('src/Resources/config/pass.txt', 'class X implements CompilerPassInterface {}');
        self::assertSame([], $this->files());
    }

    public function testChecksEveryClassOfAFile(): void
    {
        $this->write('src/DependencyInjection/Passes.php', <<<'PHP'
            <?php
            namespace Acme\DependencyInjection;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            final class Helper {}
            final class FirstPass implements CompilerPassInterface {}
            final class SecondPass implements CompilerPassInterface {}
            PHP);

        self::assertSame([
            'src/DependencyInjection/Passes.php: declares compiler pass FirstPass: compiler passes live in src/DependencyInjection/CompilerPass/',
            'src/DependencyInjection/Passes.php: declares compiler pass SecondPass: compiler passes live in src/DependencyInjection/CompilerPass/',
        ], $this->messages());
    }

    public function testReportsAnAnonymousPassInAMethod(): void
    {
        $this->write('src/AcmeBundle.php', <<<'PHP'
            <?php
            namespace Acme;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            use Symfony\Component\DependencyInjection\ContainerBuilder;
            final class AcmeBundle
            {
                public function build(ContainerBuilder $container): void
                {
                    $container->addCompilerPass(new class (static fn (): bool => true) implements CompilerPassInterface {
                        public function __construct(private \Closure $enabled) {}
                        public function process(ContainerBuilder $container): void {}
                    });
                }
            }
            PHP);

        self::assertSame(
            ['src/AcmeBundle.php: declares an anonymous compiler pass: compiler passes live in src/DependencyInjection/CompilerPass/'],
            $this->messages(),
        );
    }

    public function testResetsImportsPerNamespace(): void
    {
        $this->write('src/Two.php', <<<'PHP'
            <?php
            namespace Acme\A {
                use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
                final class Plain {}
            }
            namespace Acme\B {
                final class Local implements CompilerPassInterface {}
            }
            PHP);

        self::assertSame([], $this->files());
    }

    public function testComparesClassNamesCaseInsensitively(): void
    {
        $this->write('src/A.php', <<<'PHP'
            <?php
            namespace Acme;
            use symfony\component\dependencyinjection\compiler\COMPILERPASSINTERFACE;
            final class A implements CompilerPassInterface {}
            PHP);

        self::assertSame(['src/A.php'], $this->files());
    }

    public function testReportsChildrenOfSymfonyPasses(): void
    {
        $this->write('src/A.php', <<<'PHP'
            <?php
            namespace Acme;
            use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
            final class A extends RegisterListenersPass {}
            PHP);
        $this->write('src/B.php', <<<'PHP'
            <?php
            namespace Acme;
            use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
            final class B extends AbstractBundle {}
            PHP);

        self::assertSame(['src/A.php'], $this->files());
    }

    public function testIgnoresTraitUseInsideClassBodies(): void
    {
        $this->write('src/Passes.php', <<<'PHP'
            <?php
            namespace Acme;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            final class First { use CompilerPassInterface; }
            final class Second implements CompilerPassInterface {}
            PHP);

        self::assertSame(
            ['src/Passes.php: declares compiler pass Second: compiler passes live in src/DependencyInjection/CompilerPass/'],
            $this->messages(),
        );
    }

    public function testKeepsImportsOfABracedNamespace(): void
    {
        $this->write('src/Braced.php', <<<'PHP'
            <?php
            namespace Acme {
                use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
                final class Pass implements CompilerPassInterface {}
            }
            PHP);

        self::assertSame(['src/Braced.php'], $this->files());
    }

    public function testNamesModifiedAndAttributedAnonymousPassesAnonymous(): void
    {
        $this->write('src/AcmeBundle.php', <<<'PHP'
            <?php
            namespace Acme;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            $a = new readonly class implements CompilerPassInterface {};
            $b = new #[\Deprecated] class implements CompilerPassInterface {};
            PHP);

        self::assertSame([
            'src/AcmeBundle.php: declares an anonymous compiler pass: compiler passes live in src/DependencyInjection/CompilerPass/',
            'src/AcmeBundle.php: declares an anonymous compiler pass: compiler passes live in src/DependencyInjection/CompilerPass/',
        ], $this->messages());
    }

    public function testReportsAnEnumPass(): void
    {
        $this->write('src/Kind.php', <<<'PHP'
            <?php
            namespace Acme;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            enum Kind implements CompilerPassInterface {}
            PHP);

        self::assertSame(['src/Kind.php'], $this->files());
    }

    public function testReportsEveryCompilerPassOfALibraryWherever(): void
    {
        $this->write('src/DependencyInjection/CompilerPass/FooPass.php', <<<'PHP'
            <?php
            namespace Acme\DependencyInjection\CompilerPass;
            use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
            final class FooPass implements CompilerPassInterface {}
            PHP);
        $this->write('src/Infrastructure/BarPass.php', <<<'PHP'
            <?php
            namespace Acme\Infrastructure;
            final class BarPass implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {}
            PHP);

        self::assertSame([
            'src/DependencyInjection/CompilerPass/FooPass.php: declares compiler pass FooPass: a library has no container extension; declare kind "bundle" or remove it',
            'src/Infrastructure/BarPass.php: declares compiler pass BarPass: a library has no container extension; declare kind "bundle" or remove it',
        ], array_map(static fn (Violation $v): string => $v->format(), new CompilerPassLocationRule(PackageKind::Library)->check($this->bundleDir)));
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return array_map(static fn (Violation $v): string => $v->file, new CompilerPassLocationRule()->check($this->bundleDir));
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(static fn (Violation $v): string => $v->format(), new CompilerPassLocationRule()->check($this->bundleDir));
    }
}
