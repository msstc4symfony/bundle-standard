<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use Msstc4Symfony\BundleStandard\RuntimeProfile;
use Msstc4Symfony\BundleStandard\Violation;
use Override;

final readonly class RuntimeProfileRule implements RuleInterface
{
    #[Override]
    public function check(string $bundlePath): array
    {
        if (RuntimeProfile::declaredBy($bundlePath) instanceof RuntimeProfile) {
            return [];
        }

        $known = implode(' or ', array_map(static fn (RuntimeProfile $profile): string => '"' . $profile->value . '"', RuntimeProfile::cases()));

        return [new Violation(
            'composer.json',
            sprintf('extra.bundle-standard.runtime must be %s, found %s', $known, RuntimeProfile::declaration($bundlePath) ?? 'nothing'),
        )];
    }
}
