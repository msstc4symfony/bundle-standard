<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use Msstc4Symfony\BundleStandard\Rule\DeptracConfigRule;
use Msstc4Symfony\BundleStandard\StandardTemplateException;
use Msstc4Symfony\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeptracConfigRule::class)]
final class DeptracConfigRuleTest extends TestCase
{
    use TemporaryBundleTrait;

    private const string TEMPLATE = __DIR__ . '/../../../templates/deptrac.yaml';

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
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(
            static fn (Violation $v): string => $v->message,
            new DeptracConfigRule(self::TEMPLATE)->check($this->bundleDir),
        );
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
}
