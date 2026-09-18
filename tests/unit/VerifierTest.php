<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard\Test\Unit;

use MaxShamaev\BundleStandard\Rule\RuleInterface;
use MaxShamaev\BundleStandard\Verifier;
use MaxShamaev\BundleStandard\Violation;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Verifier::class)]
final class VerifierTest extends TestCase
{
    public function testCollectsViolationsFromEveryRule(): void
    {
        $verifier = new Verifier([
            $this->ruleReturning([new Violation('a.php', 'first')]),
            $this->ruleReturning([]),
            $this->ruleReturning([new Violation('b.php', 'second')]),
        ]);

        $violations = $verifier->verify('/tmp/whatever');

        self::assertCount(2, $violations);
        self::assertSame('a.php', $violations[0]->file);
        self::assertSame('b.php', $violations[1]->file);
    }

    public function testReturnsEmptyListWhenAllRulesPass(): void
    {
        $verifier = new Verifier([$this->ruleReturning([]), $this->ruleReturning([])]);

        self::assertSame([], $verifier->verify('/tmp/whatever'));
    }

    /**
     * @param list<Violation> $violations
     */
    private function ruleReturning(array $violations): RuleInterface
    {
        return new class($violations) implements RuleInterface {
            /**
             * @param list<Violation> $violations
             */
            public function __construct(private readonly array $violations)
            {
            }

            #[Override]
            public function check(string $bundlePath): array
            {
                return $this->violations;
            }
        };
    }
}
