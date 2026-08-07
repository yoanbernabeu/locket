<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Exception\InvalidPayloadException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Hachage et vérification du PIN (Argon2id, configuré dans security.yaml).
 *
 * Le PIN ne dérive AUCUNE clé cryptographique : six chiffres protégeant une clé seraient
 * cassables hors ligne en quelques secondes depuis un dump d'IndexedDB (PROTOCOL.md §1).
 * Il ne sert qu'à authentifier une session auprès du serveur.
 */
final class PinHasher
{
    private const string PIN_PATTERN = '/\A\d{6}\z/';

    /**
     * Motif de repli haché à la volée pour vérifier un PIN contre un compte inexistant.
     * Sans lui, l'absence de calcul Argon2id rendrait un pseudo inconnu mesurable au
     * chronomètre — un oracle d'existence de compte.
     */
    private const string DUMMY_PIN = '000000';

    private ?string $dummyHash = null;

    public function __construct(private readonly PasswordHasherFactoryInterface $hasherFactory)
    {
    }

    /**
     * Empreinte de repli, calculée une seule fois puis mémorisée.
     *
     * Le calcul paresseux annulait la protection qu'il était censé apporter :
     * la toute première tentative sur un pseudo inconnu payait un `hash()` en
     * plus du `verify()`, soit le double du temps d'une tentative sur un compte
     * existant. Une seule requête par pseudo suffisait à savoir s'il existait,
     * sans jamais déclencher de limitation.
     */
    private function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hasherFactory
            ->getPasswordHasher(User::class)
            ->hash(self::DUMMY_PIN);
    }

    public function assertValidPin(string $pin): void
    {
        if (1 !== preg_match(self::PIN_PATTERN, $pin)) {
            throw new InvalidPayloadException('Le code doit comporter exactement six chiffres.');
        }
    }

    public function hash(string $pin): string
    {
        $this->assertValidPin($pin);

        return $this->hasherFactory->getPasswordHasher(User::class)->hash($pin);
    }

    /**
     * Vérifie un PIN. Un utilisateur nul est traité exactement comme un utilisateur existant
     * dont le PIN serait faux : même travail cryptographique, même résultat.
     */
    public function verify(?User $user, string $pin): bool
    {
        $hasher = $this->hasherFactory->getPasswordHasher(User::class);

        // L'empreinte de repli est préparée AVANT de savoir si l'utilisateur
        // existe : les deux chemins effectuent alors exactement le même travail.
        $repli = $this->dummyHash();

        if (null === $user) {
            $hasher->verify($repli, $pin);

            return false;
        }

        return $hasher->verify($user->getPinHash(), $pin);
    }

    /**
     * Prépare l'empreinte de repli hors du chemin d'authentification.
     * Appelée au démarrage pour que la première tentative ne soit pas plus lente.
     */
    public function warmUp(): void
    {
        $this->dummyHash();
    }
}
