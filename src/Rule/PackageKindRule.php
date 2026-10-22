<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use Msstc4Symfony\BundleStandard\PackageKind;
use Msstc4Symfony\BundleStandard\Violation;
use Override;

final readonly class PackageKindRule implements RuleInterface
{
    private const string MESSAGE = 'extra.bundle-standard.kind must be %s, found %s';

    #[Override]
    public function check(string $bundlePath): array
    {
        // An absent declaration is the default kind.
        $declaration = PackageKind::declaration($bundlePath);
        if ($declaration === null || PackageKind::declaredBy($bundlePath) instanceof PackageKind) {
            return [];
        }

        $known = implode(' or ', array_map(
            static fn (PackageKind $kind): string => '"' . $kind->value . '"',
            PackageKind::cases(),
        ));

        return [new Violation('composer.json', \sprintf(self::MESSAGE, $known, $declaration))];
    }
}
