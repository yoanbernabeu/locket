<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\LockoutPolicy;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class LockoutPolicyTest extends TestCase
{
    #[TestDox('C15 : le compteur d\'échecs retombe après vingt-quatre heures sans tentative')]
    public function testCounterDecaysAfterADayWithoutAttempt(): void
    {
        $policy = new LockoutPolicy();
        $now = new \DateTimeImmutable('2026-08-07 12:00:00');

        // Sans décroissance, trois erreurs étalées sur trois ans verrouillent le compte à la
        // quatrième connexion, et un tiers maintient un membre bloqué à raison d'une
        // tentative par jour.
        self::assertTrue($policy->hasDecayed($now->modify('-24 hours'), $now));
        self::assertTrue($policy->hasDecayed($now->modify('-1 year'), $now));
        self::assertFalse($policy->hasDecayed($now->modify('-23 hours -59 minutes'), $now));
        self::assertFalse($policy->hasDecayed($now, $now));
    }

    #[TestDox('C15 : l\'absence de toute tentative antérieure vaut décroissance')]
    public function testNoPreviousAttemptCountsAsDecayed(): void
    {
        self::assertTrue((new LockoutPolicy())->hasDecayed(null, new \DateTimeImmutable()));
    }

    private LockoutPolicy $policy;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->policy = new LockoutPolicy();
        $this->now = new \DateTimeImmutable('2026-01-01 12:00:00');
    }

    #[TestDox('les premières erreurs ne verrouillent pas le compte')]
    public function testEarlyFailuresDoNotLock(): void
    {
        self::assertNull($this->policy->lockUntil(0, $this->now));
        self::assertNull($this->policy->lockUntil(1, $this->now));
        self::assertNull($this->policy->lockUntil(2, $this->now));
    }

    #[TestDox('le verrouillage démarre au seuil et double à chaque échec supplémentaire')]
    public function testLockGrowsExponentially(): void
    {
        self::assertSame(30, $this->policy->lockSeconds(3));
        self::assertSame(60, $this->policy->lockSeconds(4));
        self::assertSame(120, $this->policy->lockSeconds(5));
        self::assertSame(240, $this->policy->lockSeconds(6));
    }

    #[TestDox('le délai de verrouillage est plafonné à une heure')]
    public function testLockIsCapped(): void
    {
        self::assertSame(LockoutPolicy::MAX_LOCK_SECONDS, $this->policy->lockSeconds(30));
        self::assertSame(LockoutPolicy::MAX_LOCK_SECONDS, $this->policy->lockSeconds(1000));
    }

    #[TestDox('la date de déverrouillage est calculée à partir de l\'instant fourni')]
    public function testLockUntilUsesProvidedClock(): void
    {
        $until = $this->policy->lockUntil(4, $this->now);

        self::assertNotNull($until);
        self::assertSame('2026-01-01 12:01:00', $until->format('Y-m-d H:i:s'));
    }

    #[TestDox('mille tentatives resteraient étalées sur des heures')]
    public function testBruteForceBecomesImpractical(): void
    {
        $total = 0;

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $total += $this->policy->lockSeconds($attempt);
        }

        // Vingt tentatives coûtent déjà plus de dix heures d'attente cumulée.
        self::assertGreaterThan(10 * 3600, $total);
    }
}
