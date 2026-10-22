<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use Msstc4Symfony\BundleStandard\PackageKind;
use Msstc4Symfony\BundleStandard\Rule\DeptracConfigRule;
use Msstc4Symfony\BundleStandard\StandardTemplateException;
use Msstc4Symfony\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(DeptracConfigRule::class)]
final class DeptracConfigRuleTest extends TestCase
{
    use TemporaryBundleTrait;

    private const string TEMPLATE = __DIR__ . '/../../../templates/deptrac.yaml';

    private const string LIBRARY_TEMPLATE = __DIR__ . '/../../../templates/deptrac-library.yaml';

    private const string BUNDLE_PATTERN = '^Acme\\\\FooBundle\\\\FooBundle$';

    protected function setUp(): void
    {
        $this->createBundle();
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\FooBundle\\\\":"src/"}}}');
        $this->write('src/FooBundle.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->removeBundle();
    }

    public function testAcceptsTheTemplateWithTheBundleClass(): void
    {
        $this->write('deptrac.yaml', $this->canonical());

        self::assertSame([], $this->messages());
    }

    public function testAcceptsANarrowerRuleset(): void
    {
        $this->write('deptrac.yaml', $this->withRuleset("        Application: ~\n        BundleRoot:\n            - DependencyInjection\n"));

        self::assertSame([], $this->messages());
    }

    public function testLeavesAMissingFileToFileExistsRule(): void
    {
        self::assertSame([], $this->messages());
    }

    public function testReportsInvalidYaml(): void
    {
        $this->write('deptrac.yaml', "deptrac:\n  paths: [\n");

        self::assertStringStartsWith('is not valid YAML: ', $this->messages()[0]);
    }

    public function testReportsAMissingRoot(): void
    {
        $this->write('deptrac.yaml', "parameters: {}\n");

        self::assertSame(['must have a "deptrac" mapping at the root'], $this->messages());
    }

    public function testReportsExtraKeysAndOtherPaths(): void
    {
        $config = str_replace(
            "    paths:\n        - ./src\n",
            "    paths:\n        - ./src\n        - ./tests\n    exclude_files:\n        - '#.*test.*#i'\n    skip_violations: {}\n",
            $this->canonical(),
        );
        $this->write('deptrac.yaml', $config);

        self::assertSame([
            'key "deptrac.exclude_files" is not allowed: the layer rules apply to all of src/ without exceptions',
            'key "deptrac.skip_violations" is not allowed: the layer rules apply to all of src/ without exceptions',
            'deptrac.paths must be ["./src"]',
        ], $this->messages());
    }

    public function testNamesTheFirstLayerThatDiffersFromTheTemplate(): void
    {
        $this->write('deptrac.yaml', str_replace('src/Framework/.*', 'src/Integration/.*', $this->canonical()));

        self::assertSame(
            ['deptrac.layers[5] (Framework) differs from bundle-standard templates/deptrac.yaml: copy the canonical layers as-is, with the BundleRoot value ^Acme\\\\FooBundle\\\\FooBundle$'],
            $this->messages(),
        );
    }

    public function testReportsAMissingLayer(): void
    {
        $config = preg_replace('/^        - name: Framework\n.*?(?=^        - name: DependencyInjection)/ms', '', $this->canonical());
        self::assertIsString($config);
        $this->write('deptrac.yaml', $config);

        self::assertSame(
            ['deptrac.layers must declare the 8 canonical layers of bundle-standard templates/deptrac.yaml, found 7: copy them as-is, with the BundleRoot value ^Acme\\\\FooBundle\\\\FooBundle$'],
            $this->messages(),
        );
    }

    public function testIgnoresTheKeyOrderOfALayer(): void
    {
        $config = str_replace(
            "        - name: Application\n          collectors:\n              - type: directory\n                value: src/Application/.*\n",
            "        - collectors:\n              - value: src/Application/.*\n                type: directory\n          name: Application\n",
            $this->canonical(),
        );
        self::assertNotSame($this->canonical(), $config);
        $this->write('deptrac.yaml', $config);

        self::assertSame([], $this->messages());
    }

    public function testReportsRootKeysOtherThanDeptrac(): void
    {
        $this->write('deptrac.yaml', "imports:\n    - extra.yaml\nservices: {}\n" . $this->canonical());

        self::assertSame([
            'root key "imports" is not allowed: deptrac.yaml holds only the "deptrac" mapping',
            'root key "services" is not allowed: deptrac.yaml holds only the "deptrac" mapping',
        ], $this->messages());
    }

    public function testRejectsTransitiveDependencies(): void
    {
        $this->write('deptrac.yaml', $this->withRuleset("        Application:\n            - +Domain\n"));

        self::assertSame(
            ['deptrac.ruleset.Application: transitive dependency "+Domain" is not allowed, list the layers explicitly'],
            $this->messages(),
        );
    }

    public function testLeavesAMissingBundleClassFileToSourceLayoutRule(): void
    {
        unlink($this->bundleDir . '/src/FooBundle.php');
        $this->write('deptrac.yaml', $this->canonical());

        self::assertSame([], $this->messages());
    }

    public function testReportsABundleRootForAnotherClass(): void
    {
        $this->write('deptrac.yaml', str_replace(self::BUNDLE_PATTERN, '^Acme\\\\Other\\\\OtherBundle$', $this->canonical()));

        self::assertCount(1, $this->messages());
    }

    public function testReportsAnUnderivableBundleClass(): void
    {
        $this->write('composer.json', '{"autoload":{"psr-4":{"Acme\\\\FooBundle\\\\":"lib/"}}}');
        $this->write('deptrac.yaml', $this->canonical());

        self::assertSame(
            ['cannot derive the bundle class for the BundleRoot layer: composer.json needs exactly one autoload.psr-4 prefix for "src/"'],
            $this->messages(),
        );
    }

    public function testReportsUnknownLayersAndWiderDependencies(): void
    {
        $this->write('deptrac.yaml', $this->withRuleset(
            "        Storage: ~\n        Presentation:\n            - Application\n            - Infrastructure\n        Domain:\n            - Application\n",
        ));

        self::assertSame([
            'deptrac.ruleset.Storage: unknown layer',
            'deptrac.ruleset.Presentation must not depend on Infrastructure (canonical: Domain, Application)',
            'deptrac.ruleset.Domain must not depend on Application (canonical: nothing)',
        ], $this->messages());
    }

    public function testReportsARulesetThatIsNotAListOfNames(): void
    {
        $this->write('deptrac.yaml', $this->withRuleset("        Application: Domain\n"));

        self::assertSame(['deptrac.ruleset.Application must be a list of layer names'], $this->messages());
    }

    public function testRejectsABrokenTemplateEvenWithoutABundleConfig(): void
    {
        $this->write('broken-template.yaml', "deptrac: {}\n");

        $this->expectException(StandardTemplateException::class);

        new DeptracConfigRule($this->bundleDir . '/broken-template.yaml')->check($this->bundleDir);
    }

    public function testRejectsATemplateThatIsNotYaml(): void
    {
        $this->write('broken-template.yaml', "deptrac: [\n");

        $this->expectException(StandardTemplateException::class);

        new DeptracConfigRule($this->bundleDir . '/broken-template.yaml')->check($this->bundleDir);
    }

    public function testRejectsATemplateOfTheOtherKind(): void
    {
        foreach ([[self::LIBRARY_TEMPLATE, PackageKind::Bundle], [self::TEMPLATE, PackageKind::Library]] as [$template, $kind]) {
            try {
                new DeptracConfigRule($template, $kind)->check($this->bundleDir);
                self::fail('A template of the other kind was accepted: ' . $template);
            } catch (StandardTemplateException $e) {
                self::assertStringContainsString(basename($template), $e->getMessage());
            }
        }
    }

    public function testRejectsABundleRootLayerWithoutThePlaceholderAndTheOtherWayRound(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);
        $this->write('no-placeholder.yaml', str_replace("'" . DeptracConfigRule::BUNDLE_ROOT_PLACEHOLDER . "'", '^Acme\\\\Foo$', $template));
        $this->write('stray-placeholder.yaml', str_replace('src/Framework/.*', DeptracConfigRule::BUNDLE_ROOT_PLACEHOLDER, (string) file_get_contents(self::LIBRARY_TEMPLATE)));

        foreach ([['no-placeholder.yaml', PackageKind::Bundle], ['stray-placeholder.yaml', PackageKind::Library]] as [$file, $kind]) {
            try {
                new DeptracConfigRule($this->bundleDir . '/' . $file, $kind)->check($this->bundleDir);
                self::fail('An inconsistent template was accepted: ' . $file);
            } catch (StandardTemplateException $e) {
                self::assertStringContainsString($file, $e->getMessage());
            }
        }
    }

    public function testRejectsAnUnanchoredBundleRoot(): void
    {
        $this->write('deptrac.yaml', str_replace(self::BUNDLE_PATTERN, 'Acme\\\\FooBundle\\\\FooBundle', $this->canonical()));

        self::assertCount(1, $this->messages());
    }

    public function testStillComparesTheOtherLayersWithoutABundleClass(): void
    {
        unlink($this->bundleDir . '/src/FooBundle.php');
        $this->write('deptrac.yaml', str_replace('src/Framework/.*', 'src/Integration/.*', $this->canonical()));

        self::assertSame(
            ['deptrac.layers[5] (Framework) differs from bundle-standard templates/deptrac.yaml: copy the canonical layers as-is, with the BundleRoot value set to the bundle class'],
            $this->messages(),
        );
    }

    public function testAcceptsTheLibraryTemplateWithoutABundleClass(): void
    {
        unlink($this->bundleDir . '/src/FooBundle.php');
        $this->write('composer.json', '{}');
        $this->write('deptrac.yaml', (string) file_get_contents(self::LIBRARY_TEMPLATE));

        self::assertSame([], $this->messages(self::LIBRARY_TEMPLATE));
    }

    public function testRejectsTheBundleLayersInALibrary(): void
    {
        $this->write('deptrac.yaml', $this->canonical());

        self::assertSame([
            'deptrac.layers must declare the 6 canonical layers of bundle-standard templates/deptrac-library.yaml, found 8: copy them as-is',
            'deptrac.ruleset.DependencyInjection: unknown layer',
            'deptrac.ruleset.BundleRoot: unknown layer',
        ], $this->messages(self::LIBRARY_TEMPLATE));
    }

    public function testNamesTheLibraryTemplateForADifferingLayer(): void
    {
        $this->write('deptrac.yaml', str_replace('src/Framework/.*', 'src/Integration/.*', (string) file_get_contents(self::LIBRARY_TEMPLATE)));

        self::assertSame(
            ['deptrac.layers[5] (Framework) differs from bundle-standard templates/deptrac-library.yaml: copy the canonical layers as-is'],
            $this->messages(self::LIBRARY_TEMPLATE),
        );
    }

    public function testTheLibraryTemplateIsTheBundleTemplateWithoutTheBundleLayers(): void
    {
        $bundle = self::deptrac(self::TEMPLATE);
        $library = self::deptrac(self::LIBRARY_TEMPLATE);
        $bundleOnly = ['DependencyInjection', 'BundleRoot'];

        self::assertIsArray($bundle['layers']);
        self::assertIsArray($bundle['ruleset']);
        $expectedRuleset = [];
        foreach ($bundle['ruleset'] as $layer => $dependencies) {
            if (!\in_array($layer, $bundleOnly, true)) {
                $expectedRuleset[$layer] = \is_array($dependencies) ? array_values(array_diff(array_filter($dependencies, is_string(...)), $bundleOnly)) : $dependencies;
            }
        }

        self::assertSame(
            [
                'paths' => $bundle['paths'],
                'layers' => array_values(array_filter(
                    $bundle['layers'],
                    static fn (mixed $layer): bool => !\is_array($layer) || !\in_array($layer['name'] ?? null, $bundleOnly, true),
                )),
                'ruleset' => $expectedRuleset,
            ],
            $library,
        );
        self::assertStringNotContainsString(DeptracConfigRule::BUNDLE_ROOT_PLACEHOLDER, (string) file_get_contents(self::LIBRARY_TEMPLATE));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function deptrac(string $file): array
    {
        $parsed = Yaml::parseFile($file);
        self::assertIsArray($parsed);
        self::assertSame(['deptrac'], array_keys($parsed));
        self::assertIsArray($parsed['deptrac']);

        return $parsed['deptrac'];
    }

    private function canonical(): string
    {
        $template = file_get_contents(self::TEMPLATE);
        self::assertIsString($template);

        return str_replace("'" . DeptracConfigRule::BUNDLE_ROOT_PLACEHOLDER . "'", self::BUNDLE_PATTERN, $template);
    }

    private function withRuleset(string $ruleset): string
    {
        $config = preg_replace('/^    ruleset:.*\z/ms', "    ruleset:\n" . $ruleset, $this->canonical());
        self::assertIsString($config);

        return $config;
    }

    /**
     * @param non-empty-string $template
     *
     * @return list<string>
     */
    private function messages(string $template = self::TEMPLATE): array
    {
        return array_map(
            static fn (Violation $v): string => $v->message,
            new DeptracConfigRule($template, $template === self::LIBRARY_TEMPLATE ? PackageKind::Library : PackageKind::Bundle)->check($this->bundleDir),
        );
    }
}
