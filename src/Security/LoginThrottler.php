<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\LoginAttempt;
use App\Entity\User;
use App\Repository\LoginAttemptRepository;
use App\Service\Auth\LockoutPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Triple barrière sur la connexion, seule protection d'un PIN à six chiffres :
 *
 * 1. limiteur par pseudo — empêche de marteler un compte précis, même depuis un botnet ;
 * 2. limiteur par IP — empêche de balayer tous les comptes depuis une même source ;
 * 3. verrouillage exponentiel persistant PAR PSEUDO — survit au redémarrage et au vidage
 *    du cache, contrairement aux deux premiers.
 *
 * Les trois sont nécessaires : chacune couvre l'angle mort des autres.
 *
 * ABSENCE D'ORACLE D'EXISTENCE : le verrouillage est indexé sur le pseudo présenté
 * (App\Entity\LoginAttempt), pas sur un compte. Un pseudo inconnu est donc verrouillé au même
 * rang, avec le même délai et le même code 429 qu'un pseudo existant ; la séquence de réponses
 * ne dit plus rien de l'existence du compte. Les colonnes `failedAttempts` / `lockedUntil` de
 * `user` continuent d'être tenues à jour, à titre de trace consultable en administration —
 * elles ne participent plus à la décision.
 */
final class LoginThrottler
{
    public function __construct(
        #[Target('loginPseudoLimiter')]
        private readonly RateLimiterFactoryInterface $pseudoLimiter,
        #[Target('loginIpLimiter')]
        private readonly RateLimiterFactoryInterface $ipLimiter,
        private readonly LockoutPolicy $policy,
        private readonly LoginAttemptRepository $attempts,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws TooManyAttemptsException
     */
    public function assertNotThrottled(string $pseudo, ?string $clientIp): void
    {
        $pseudoLimit = $this->pseudoLimiter->create(LoginAttempt::normalize($pseudo))->consume();

        if (!$pseudoLimit->isAccepted()) {
            throw $this->tooMany($pseudoLimit->getRetryAfter());
        }

        $ipLimit = $this->ipLimiter->create($clientIp ?? 'unknown')->consume();

        if (!$ipLimit->isAccepted()) {
            throw $this->tooMany($ipLimit->getRetryAfter());
        }
    }

    /**
     * @throws TooManyAttemptsException
     */
    public function assertNotLocked(string $pseudo): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $attempt = $this->attempts->findOneByPseudo(LoginAttempt::normalize($pseudo));

        if (null === $attempt || !$attempt->isLockedAt($now)) {
            return;
        }

        $lockedUntil = $attempt->getLockedUntil();
        \assert($lockedUntil instanceof \DateTimeImmutable);

        throw $this->tooMany($lockedUntil);
    }

    /**
     * @param User|null $user compte correspondant au pseudo, s'il en existe un
     */
    public function registerFailure(string $pseudo, ?User $user): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $normalized = LoginAttempt::normalize($pseudo);
        $attempt = $this->attempts->findOneByPseudo($normalized);

        if (null === $attempt) {
            $attempt = new LoginAttempt($normalized, $now);
            $this->entityManager->persist($attempt);
        }

        // Décroissance : une journée entière sans tentative remet le compteur à zéro avant
        // d'enregistrer l'échec courant, qui redevient donc le premier.
        $previous = $this->policy->hasDecayed($attempt->getLastAttemptAt(), $now) ? 0 : $attempt->getFailedAttempts();
        $failedAttempts = $previous + 1;

        $lockedUntil = $this->policy->lockUntil($failedAttempts, $now);
        $attempt->registerFailure($failedAttempts, $lockedUntil, $now);
        $user?->applyFailureState($failedAttempts, $lockedUntil);

        $this->entityManager->flush();
    }

    public function registerSuccess(User $user): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->attempts->findOneByPseudo(LoginAttempt::normalize($user->getPseudo()))?->reset($now);
        $user->resetFailedAttempts();
        $this->entityManager->flush();
    }

    private function tooMany(\DateTimeInterface $retryAfter): TooManyAttemptsException
    {
        $exception = new TooManyAttemptsException();
        $exception->setRetryAfterSeconds($retryAfter->getTimestamp() - $this->clock->now()->getTimestamp());

        return $exception;
    }
}
