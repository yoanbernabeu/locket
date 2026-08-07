<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Dto\DeviceEnrollment;
use App\Entity\Conversation;
use App\Entity\Device;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Service\Device\DeviceService;
use App\Service\Installation\InstallationService;
use App\Service\Key\ConversationKeyService;
use App\Service\Membership\MembershipService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

/**
 * Construction rapide d'un état applicatif réaliste, par les services du domaine plutôt que
 * par des insertions brutes : les tests exercent ainsi les mêmes invariants que la production.
 */
final class Fixtures
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @return array{user: User, device: Device, conversation: Conversation}
     */
    public function install(string $pseudo = 'papa', string $pin = '123456'): array
    {
        $installation = $this->container->get(InstallationService::class);
        \assert($installation instanceof InstallationService);

        $result = $installation->install(
            'Famille Test',
            $pseudo,
            'Papa',
            'papa@example.test',
            $pin,
            new DeviceEnrollment(EnvelopeFactory::deviceId(), EnvelopeFactory::publicKey(), EnvelopeFactory::publicKey(), 'Téléphone de papa'),
        );

        return ['user' => $result['user'], 'device' => $result['device'], 'conversation' => $result['conversation']];
    }

    /**
     * @return array{user: User, device: Device}
     */
    public function addMember(string $pseudo, string $pin = '654321', bool $isAdmin = false): array
    {
        $entityManager = $this->container->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $hasher = $this->container->get(\App\Service\Auth\PinHasher::class);
        \assert($hasher instanceof \App\Service\Auth\PinHasher);

        $user = new User($pseudo, ucfirst($pseudo), $hasher->hash($pin), new \DateTimeImmutable(), $isAdmin);
        $entityManager->persist($user);
        $entityManager->flush();

        $devices = $this->container->get(DeviceService::class);
        \assert($devices instanceof DeviceService);

        $device = $devices->registerBootstrapDevice(
            $user,
            EnvelopeFactory::deviceId(),
            EnvelopeFactory::publicKey(),
            EnvelopeFactory::publicKey(),
            'Téléphone de '.$pseudo,
        );

        $membership = $this->container->get(MembershipService::class);
        \assert($membership instanceof MembershipService);
        $membership->addToGlobalGroup($user);

        return ['user' => $user, 'device' => $device];
    }

    public function globalGroup(): Conversation
    {
        $conversations = $this->container->get(ConversationRepository::class);
        \assert($conversations instanceof ConversationRepository);

        return $conversations->findGlobalGroup() ?? throw new \RuntimeException('Groupe global absent.');
    }

    /**
     * Rotation de clés « telle que la ferait un client » : une époque égale au
     * memberSetVersion courant, un wrap par appareil du roster.
     */
    public function rotateKeys(Conversation $conversation): int
    {
        $keys = $this->container->get(ConversationKeyService::class);
        \assert($keys instanceof ConversationKeyService);

        $devices = $this->container->get(\App\Repository\DeviceRepository::class);
        \assert($devices instanceof \App\Repository\DeviceRepository);

        $version = $conversation->getMemberSetVersion();
        $wraps = [];

        foreach ($devices->findActiveDevicesForConversation($conversation) as $device) {
            $wraps[] = ['recipientDeviceId' => $device->getDeviceId(), 'wrap' => EnvelopeFactory::wrap()];
        }

        $keys->deposit($conversation, $version, $version, $wraps, EnvelopeFactory::attestation());

        return $version;
    }
}
