<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Repository\InstanceRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Source unique des clés VAPID.
 *
 * L'environnement fait foi (`locket:install` y écrit les clés). L'instance en base sert de
 * repli pour l'installation faite depuis le navigateur : un processus web n'a aucune garantie
 * de pouvoir écrire `.env.local`, et une instance sans clés VAPID est une instance sans
 * notifications — donc sans usage réel.
 */
final class VapidKeyProvider
{
    public function __construct(
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $envPublicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')]
        private readonly string $envPrivateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')]
        private readonly string $subject,
        private readonly InstanceRepository $instances,
    ) {
    }

    public function getPublicKey(): ?string
    {
        if ('' !== $this->envPublicKey) {
            return $this->envPublicKey;
        }

        return $this->instances->get()?->getVapidPublicKey();
    }

    private function getPrivateKey(): ?string
    {
        if ('' !== $this->envPrivateKey) {
            return $this->envPrivateKey;
        }

        return $this->instances->get()?->getVapidPrivateKey();
    }

    public function isConfigured(): bool
    {
        return null !== $this->getPublicKey() && null !== $this->getPrivateKey();
    }

    /**
     * @return array{VAPID: array{subject: string, publicKey: string, privateKey: string}}|null
     */
    public function getAuth(): ?array
    {
        $public = $this->getPublicKey();
        $private = $this->getPrivateKey();

        if (null === $public || null === $private) {
            return null;
        }

        return ['VAPID' => [
            'subject' => $this->subject,
            'publicKey' => $public,
            'privateKey' => $private,
        ]];
    }
}
