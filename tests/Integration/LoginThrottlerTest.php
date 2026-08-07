<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\LoginAttempt;
use App\Repository\LoginAttemptRepository;
use App\Security\LoginThrottler;
use App\Security\TooManyAttemptsException;
use App\Service\Auth\LockoutPolicy;
use App\Tests\Support\DomainTestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class LoginThrottlerTest extends DomainTestCase
{
    private LoginAttemptRepository $attempts;

    protected function setUp(): void
    {
        parent::setUp();

        $attempts = self::getContainer()->get(LoginAttemptRepository::class);
        \assert($attempts instanceof LoginAttemptRepository);
        $this->attempts = $attempts;

        $this->fixtures->install('papa', '123456');
    }

    #[TestDox('C3 : un pseudo inconnu se verrouille exactement comme un pseudo existant')]
    public function testUnknownPseudoIsLockedLikeAnExistingOne(): void
    {
        $now = new \DateTimeImmutable('2026-08-07 12:00:00');
        $throttler = $this->throttlerAt($now);

        $papa = $this->users()->findOneByPseudo('papa');
        self::assertNotNull($papa);

        for ($i = 0; $i < LockoutPolicy::FREE_ATTEMPTS; ++$i) {
            $throttler->registerFailure('papa', $papa);
            $throttler->registerFailure('fantome', null);
        }

        // Le verrou existe des deux côtés, avec la même échéance : le code de réponse ne dit
        // donc plus rien de l'existence du compte.
        $known = $this->attempts->findOneByPseudo('papa');
        $unknown = $this->attempts->findOneByPseudo('fantome');

        self::assertNotNull($known);
        self::assertNotNull($unknown);
        self::assertSame($known->getFailedAttempts(), $unknown->getFailedAttempts());
        self::assertEquals($known->getLockedUntil(), $unknown->getLockedUntil());

        foreach (['papa', 'fantome'] as $pseudo) {
            try {
                $throttler->assertNotLocked($pseudo);
                self::fail('Un verrouillage était attendu pour « '.$pseudo.' ».');
            } catch (TooManyAttemptsException) {
                self::assertTrue(true);
            }
        }
    }

    #[TestDox('C15 : après vingt-quatre heures sans tentative, le compteur repart de zéro')]
    public function testCounterDecaysAfterOneDay(): void
    {
        $start = new \DateTimeImmutable('2026-08-07 12:00:00');

        for ($i = 0; $i < 6; ++$i) {
            $this->throttlerAt($start)->registerFailure('papa', $this->users()->findOneByPseudo('papa'));
        }

        $attempt = $this->attempts->findOneByPseudo('papa');
        self::assertNotNull($attempt);
        self::assertSame(6, $attempt->getFailedAttempts());

        // Une journée entière plus tard, l'échec suivant redevient le premier : le compte
        // n'est plus verrouillé, alors qu'il l'aurait été indéfiniment auparavant.
        $later = $start->modify('+25 hours');
        $this->throttlerAt($later)->registerFailure('papa', $this->users()->findOneByPseudo('papa'));

        $this->entityManager->clear();
        $attempt = $this->attempts->findOneByPseudo('papa');

        self::assertNotNull($attempt);
        self::assertSame(1, $attempt->getFailedAttempts());
        self::assertNull($attempt->getLockedUntil());
        $this->throttlerAt($later)->assertNotLocked('papa');
    }

    #[TestDox('C3 : la casse et les espaces ne créent pas de compteur distinct')]
    public function testPseudoIsNormalizedBeforeCounting(): void
    {
        $now = new \DateTimeImmutable('2026-08-07 12:00:00');

        $this->throttlerAt($now)->registerFailure('  PaPa  ', null);
        $this->throttlerAt($now)->registerFailure('papa', null);

        self::assertCount(1, $this->attempts->findAll());
        self::assertSame(2, $this->attempts->findOneByPseudo(LoginAttempt::normalize('PAPA'))?->getFailedAttempts());
    }

    #[TestDox('une connexion réussie efface le compteur du pseudo')]
    public function testSuccessClearsTheCounter(): void
    {
        $now = new \DateTimeImmutable('2026-08-07 12:00:00');
        $papa = $this->users()->findOneByPseudo('papa');
        self::assertNotNull($papa);

        $this->throttlerAt($now)->registerFailure('papa', $papa);
        $this->throttlerAt($now)->registerSuccess($papa);

        $this->entityManager->clear();

        self::assertSame(0, $this->attempts->findOneByPseudo('papa')?->getFailedAttempts());
        self::assertSame(0, $this->users()->findOneByPseudo('papa')?->getFailedAttempts());
    }

    private function throttlerAt(\DateTimeImmutable $now): LoginThrottler
    {
        $container = self::getContainer();

        $pseudoLimiter = $container->get('limiter.login_pseudo');
        $ipLimiter = $container->get('limiter.login_ip');
        \assert($pseudoLimiter instanceof RateLimiterFactoryInterface);
        \assert($ipLimiter instanceof RateLimiterFactoryInterface);

        return new LoginThrottler(
            $pseudoLimiter,
            $ipLimiter,
            new LockoutPolicy(),
            $this->attempts,
            $this->entityManager,
            new MockClock($now),
        );
    }

    private function users(): \App\Repository\UserRepository
    {
        $repository = self::getContainer()->get(\App\Repository\UserRepository::class);
        \assert($repository instanceof \App\Repository\UserRepository);

        return $repository;
    }
}
