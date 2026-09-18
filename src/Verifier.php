<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard;

use MaxShamaev\BundleStandard\Rule\RuleInterface;

final readonly class Verifier
{
    /**
     * @param list<RuleInterface> $rules
     */
    public function __construct(private array $rules)
    {
    }

    /**
     * @param non-empty-string $bundlePath
     *
     * @return list<Violation>
     */
    public function verify(string $bundlePath): array
    {
        $violations = [];

        foreach ($this->rules as $rule) {
            $violations = [...$violations, ...$rule->check($bundlePath)];
        }

        return $violations;
    }
}
