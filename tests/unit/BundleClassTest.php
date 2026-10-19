<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Msstc4Symfony\BundleStandard\BundleClass;
use Msstc4Symfony\BundleStandard\Test\Unit\Rule\TemporaryBundleTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BundleClass::class)]
final class BundleClassTest extends TestCase
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

    public function testDerivesTheClassFromThePsr4PrefixOfSrc(): void
    {
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\FooBundle\\\\":"src/"}}}');
        $this->write('src/FooBundle.php', '<?php');

        self::assertSame('Acme\FooBundle\FooBundle', BundleClass::of($this->bundleDir));
    }

    public function testAcceptsSrcWithoutTrailingSlashAndAListOfDirectories(): void
    {
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\Foo\\\\":["lib/","src"]}}}');
        $this->write('src/FooBundle.php', '<?php');

        self::assertSame('Acme\Foo\FooBundle', BundleClass::of($this->bundleDir));
    }

    public function testIsNullWithoutExactlyOneBundleFile(): void
    {
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\":"src/"}}}');
        self::assertNull(BundleClass::of($this->bundleDir));

        $this->write('src/FooBundle.php', '<?php');
        $this->write('src/BarBundle.php', '<?php');
        self::assertNull(BundleClass::of($this->bundleDir));
    }

    public function testIsNullWithoutAPsr4PrefixForSrc(): void
    {
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\":"lib/"}}}');
        $this->write('src/FooBundle.php', '<?php');

        self::assertNull(BundleClass::of($this->bundleDir));
    }

    public function testAcceptsADotSlashSrcMapping(): void
    {
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\":"./src/"}}}');
        $this->write('src/FooBundle.php', '<?php');

        self::assertSame('Acme\FooBundle', BundleClass::of($this->bundleDir));
    }

    public function testCountsOnlyFilesNamedLikeABundleClass(): void
    {
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\":"src/"}}}');
        $this->write('src/Bundle.php', '<?php');
        $this->write('src/fooBundle.php', '<?php');
        $this->write('src/FooBundle.php/.keep', '');

        self::assertNull(BundleClass::file($this->bundleDir));

        $this->write('src/BarBundle.php', '<?php');

        self::assertSame('BarBundle.php', BundleClass::file($this->bundleDir));
        self::assertSame(1, preg_match(BundleClass::FILE_PATTERN, 'BarBundle.php'));
    }

    public function testFindsTheBundleUnderAPathWithGlobCharacters(): void
    {
        $bundleDir = $this->bundleDir;
        $this->bundleDir .= '/[x]';
        mkdir($this->bundleDir);
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\":"src/"}}}');
        $this->write('src/FooBundle.php', '<?php');

        try {
            self::assertSame('Acme\FooBundle', BundleClass::of($this->bundleDir));
        } finally {
            $this->bundleDir = $bundleDir;
        }
    }

    public function testRejectsAnAbsoluteOrAmbiguousSrcMapping(): void
    {
        $this->write('src/FooBundle.php', '<?php');
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\":"/src"}}}');
        self::assertNull(BundleClass::of($this->bundleDir));

        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\":"src/","Other\\\\":"src"}}}');
        self::assertNull(BundleClass::of($this->bundleDir));
    }

    public function testFilePatternDoesNotAcceptATrailingNewline(): void
    {
        self::assertSame(0, preg_match(BundleClass::FILE_PATTERN, "FooBundle.php\n"));
    }

    public function testRejectsAPrefixWithoutTrailingSeparator(): void
    {
        $this->write('src/FooBundle.php', '<?php');
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\Foo":"src/"}}}');

        self::assertNull(BundleClass::of($this->bundleDir));
    }
}
