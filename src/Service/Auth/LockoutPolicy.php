<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Verrouillage exponentiel du compte après des PIN erronés.
 *
 * POURQUOI c'est critique : un PIN à six chiffres n'offre qu'un million de combinaisons.
 * Sans verrouillage, quelques minutes suffisent à les épuiser. Le doublement du délai rend
 * l'attaque impraticable dès la dizaine de tentatives, tout en restant indolore pour un
 * humain qui se trompe une ou deux fois.
 *
 * Classe pure et sans dépendance : c'est la logique la plus sensible du projet, elle doit
 * être testable sans base ni requête.
 */
final class LockoutPolicy
{
    /** Nombre d'échecs tolérés avant le premier verrouillage. */
    public const int FREE_ATTEMPTS = 3;

    public const int BASE_LOCK_SECONDS = 30;

    /** Plafond : au-delà, l'attaque est déjà éteinte et l'utilisateur légitime bloqué pour rien. */
    public const int MAX_LOCK_SECONDS = 3600;

    /**
     * Décroissance du compteur. Sans elle, trois erreurs étalées sur trois ans suffisent à
     * verrouiller un compte à la quatrième connexion, et un tiers peut maintenir un membre
     * verrouillé indéfiniment à raison d'une tentative par jour. Une journée sans aucune
     * tentative éteint donc entièrement le compteur.
     */
    public const int DECAY_SECONDS = 86400;

    /**
     * @param \DateTimeImmutable|null $lastAttemptAt null lorsqu'aucune tentative n'a jamais été enregistrée
     */
    public function hasDecayed(?\DateTimeImmutable $lastAttemptAt, \DateTimeImmutable $now): bool
    {
        if (null === $lastAttemptAt) {
            return true;
        }

        return $lastAttemptAt->getTimestamp() + self::DECAY_SECONDS <= $now->getTimestamp();
    }

    /**
     * @param int $failedAttempts nombre total d'échecs, incrément courant compris
     *
     * @return \DateTimeImmutable|null null tant que le seuil de tolérance n'est pas atteint
     */
    public function lockUntil(int $failedAttempts, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($failedAttempts < self::FREE_ATTEMPTS) {
            return null;
        }

        return $now->modify('+'.$this->lockSeconds($failedAttempts).' seconds');
    }

    public function lockSeconds(int $failedAttempts): int
    {
        if ($failedAttempts < self::FREE_ATTEMPTS) {
            return 0;
        }

        $exponent = $failedAttempts - self::FREE_ATTEMPTS;

        // Le plafonnement de l'exposant évite un dépassement d'entier sur un compteur
        // laissé filer par une attaque de longue haleine.
        if ($exponent > 20) {
            return self::MAX_LOCK_SECONDS;
        }

        return min(self::BASE_LOCK_SECONDS << $exponent, self::MAX_LOCK_SECONDS);
    }
}
