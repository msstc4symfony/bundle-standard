<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use Msstc4Symfony\BundleStandard\Rule\SourceLayoutRule;
use Msstc4Symfony\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceLayoutRule::class)]
final class SourceLayoutRuleTest extends TestCase
{
    use TemporaryBundleTrait;

    private const string NOT_A_LAYER = 'is not a canonical layer directory or the bundle class (allowed: Domain, Application, Infrastructure, Presentation, Framework, DependencyInjection, Resources, <Name>Bundle.php)';

    private const string NOT_A_DOMAIN_DIRECTORY = 'is not a Domain or DomainService directory (allowed: Entity, Document, ValueObject, Enum, Event, Repository, DocumentRepository, Service, Helper, Specification)';

    protected function setUp(): void
    {
        $this->createBundle();
    }

    protected function tearDown(): void
    {
        $this->removeBundle();
    }

    /**
     * @return list<string>
     */
    private function violations(): array
    {
        return array_map(static fn (Violation $v): string => $v->format(), new SourceLayoutRule()->check($this->bundleDir));
    }

    public function testAcceptsTheCanonicalLayout(): void
    {
        $this->write('src/FooBundle.php', '<?php');
        $this->write('src/.gitkeep', '');
        foreach (SourceLayoutRule::LAYER_DIRECTORIES as $dir) {
            $this->write('src/' . $dir . '/.gitkeep', '');
        }

        $this->write('src/Resources/config/services.php', "<?php\n\nreturn static function (): void {};\n");

        foreach (SourceLayoutRule::DOMAIN_DIRECTORIES as $dir) {
            $this->write('src/Domain/' . $dir . '/X.php', '<?php');
        }

        self::assertSame([], $this->violations());
    }

    public function testAcceptsABundleClassAlone(): void
    {
        $this->write('src/FooBundle.php', '<?php');

        self::assertSame([], $this->violations());
    }

    public function testReportsAMissingSrc(): void
    {
        self::assertSame(['src: is missing: the bundle class and its layers live there'], $this->violations());
    }

    public function testReportsNonCanonicalEntriesAndBundleCount(): void
    {
        $this->write('src/Monolog/Handler.php', '<?php');
        $this->write('src/Helper.php', '<?php');
        $this->write('src/FooBundle.php', '<?php');
        $this->write('src/BarBundle.php', '<?php');

        self::assertSame([
            'src/Helper.php: ' . self::NOT_A_LAYER,
            'src/Monolog: ' . self::NOT_A_LAYER,
            'src: must contain exactly one <Name>Bundle.php, found 2',
        ], $this->violations());
    }

    public function testReportsAMissingBundleClass(): void
    {
        $this->write('src/Application/X.php', '<?php');

        self::assertSame(['src: must contain exactly one <Name>Bundle.php, found 0'], $this->violations());
    }

    public function testReportsUnknownDomainEntries(): void
    {
        $this->write('src/FooBundle.php', '<?php');
        $this->write('src/Domain/Model/Order.php', '<?php');
        $this->write('src/Domain/Order.php', '<?php');

        self::assertSame([
            'src/Domain/Model: ' . self::NOT_A_DOMAIN_DIRECTORY,
            'src/Domain/Order.php: ' . self::NOT_A_DOMAIN_DIRECTORY,
        ], $this->violations());
    }

    public function testTreatsAFileNamedLikeALayerAsAnEntry(): void
    {
        $this->write('src/FooBundle.php', '<?php');
        $this->write('src/Application', 'not a directory');

        self::assertSame(['src/Application: ' . self::NOT_A_LAYER], $this->violations());
    }

    public function testReportsDotDirectoriesButNotDotFiles(): void
    {
        $this->write('src/FooBundle.php', '<?php');
        $this->write('src/.hidden/Evil.php', '<?php');
        $this->write('src/Domain/.hidden/Evil.php', '<?php');

        self::assertSame([
            'src/.hidden: ' . self::NOT_A_LAYER,
            'src/Domain/.hidden: ' . self::NOT_A_DOMAIN_DIRECTORY,
        ], $this->violations());
    }

    public function testReportsClassesUnderResources(): void
    {
        $this->write('src/FooBundle.php', '<?php');
        $this->write('src/Resources/config/services.php', "<?php\n\nreturn static function (): void {};\n");
        $this->write('src/Resources/Helper/Formatter.php', "<?php\n\nnamespace Acme;\n\nfinal readonly class Formatter\n{\n}\n");
        $this->write('src/Resources/Helper/Kind.php', "<?php\n\nenum Kind\n{\n}\n");

        self::assertSame([
            'src/Resources/Helper/Formatter.php: declares a class outside every layer: move it into a layer directory',
            'src/Resources/Helper/Kind.php: declares a class outside every layer: move it into a layer directory',
        ], $this->violations());
    }

    public function testDomainDirectoriesMatchTheTemplateCollectors(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../templates/deptrac.yaml');
        self::assertSame(2, preg_match_all('#value: src/Domain/\((.+)\)/\.\*$#m', $template, $matches));

        self::assertSame(SourceLayoutRule::DOMAIN_DIRECTORIES, explode('|', implode('|', $matches[1])));
    }

    public function testFindsClassesByTokensUnderResources(): void
    {
        $this->write('src/FooBundle.php', '<?php');
        $this->write('src/Resources/views/page.html.php', "<p>\nclass names below are generated\n</p>\n<?= \$title ?>\n");
        $this->write('src/Resources/config/anonymous.php', "<?php\n\nreturn new class {};\n");
        $this->write('src/Resources/Inline.php', "<?php #[\\Attribute] final class Hidden {}\n");

        self::assertSame(
            ['src/Resources/Inline.php: declares a class outside every layer: move it into a layer directory'],
            $this->violations(),
        );
    }
}
