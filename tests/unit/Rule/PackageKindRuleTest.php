<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use Msstc4Symfony\BundleStandard\Rule\PackageKindRule;
use Msstc4Symfony\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageKindRule::class)]
final class PackageKindRuleTest extends TestCase
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

    public function testAcceptsAMissingDeclaration(): void
    {
        self::assertSame([], $this->messages());
    }

    public function testAcceptsAKnownDeclaration(): void
    {
        $this->write('composer.json', '{"extra": {"bundle-standard": {"kind": "library"}}}');
        self::assertSame([], $this->messages());

        $this->write('composer.json', '{"extra": {"bundle-standard": {"kind": "bundle"}}}');
        self::assertSame([], $this->messages());
    }

    public function testReportsAnUnknownDeclaration(): void
    {
        $this->write('composer.json', '{"extra": {"bundle-standard": {"kind": "plugin"}}}');
        self::assertSame(['composer.json: extra.bundle-standard.kind must be "bundle" or "library", found "plugin"'], $this->messages());

        $this->write('composer.json', '{"extra": {"bundle-standard": {"kind": true}}}');
        self::assertSame(['composer.json: extra.bundle-standard.kind must be "bundle" or "library", found true'], $this->messages());
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(static fn (Violation $v): string => $v->format(), new PackageKindRule()->check($this->bundleDir));
    }
}
