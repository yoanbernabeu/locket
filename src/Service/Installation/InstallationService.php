<?php

declare(strict_types=1);

namespace App\Service\Installation;

use App\Dto\DeviceEnrollment;
use App\Entity\Conversation;
use App\Entity\Device;
use App\Entity\Instance;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Exception\ConflictException;
use App\Exception\InvalidPayloadException;
use App\Repository\InstanceRepository;
use App\Service\Auth\PinHasher;
use App\Service\Device\DeviceService;
use App\Service\Membership\MembershipService;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\VAPID;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Premier lancement : l'instance, le premier administrateur et le groupe global naissent
 * ensemble, dans une seule transaction. Une installation à moitié faite laisserait une base
 * inutilisable — pas de groupe global signifie aucun endroit où parler.
 */
final class InstallationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InstanceRepository $instances,
        private readonly PinHasher $pinHasher,
        private readonly DeviceService $deviceService,
        private readonly MembershipService $membership,
        private readonly ClockInterface $clock,
        #[Autowire('%env(LOCKET_INSTANCE_NAME)%')]
        private readonly string $defaultInstanceName,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $envVapidPublicKey,
    ) {
    }

    public function isInstalled(): bool
    {
        return $this->instances->isInstalled();
    }

    /**
     * @return array{instance: Instance, user: User, device: Device, conversation: Conversation}
     */
    public function install(
        ?string $instanceName,
        string $pseudo,
        string $displayName,
        ?string $email,
        string $pin,
        DeviceEnrollment $enrollment,
    ): array {
        if ($this->isInstalled()) {
            throw new ConflictException('Cette instance est déjà installée.');
        }

        $pseudo = trim($pseudo);

        if ('' === $pseudo || mb_strlen($pseudo) > 64) {
            throw new InvalidPayloadException('Le pseudo doit comporter entre 1 et 64 caractères.');
        }

        $this->pinHasher->assertValidPin($pin);
        $this->deviceService->assertValidDeviceId($enrollment->deviceId);

        return $this->entityManager->wrapInTransaction(function () use ($instanceName, $pseudo, $displayName, $email, $pin, $enrollment): array {
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $name = null !== $instanceName && '' !== trim($instanceName) ? trim($instanceName) : $this->defaultInstanceName;

            $instance = $this->instances->get() ?? new Instance($name);
            $instance->setName($name);
            $instance->markInstalled($now);

            // Les clés VAPID ne sont générées que si l'environnement n'en fournit pas :
            // `locket:install` reste la voie normale, celle-ci est le repli du navigateur.
            if ('' === $this->envVapidPublicKey && null === $instance->getVapidPublicKey()) {
                $keys = VAPID::createVapidKeys();
                $instance->setVapidKeys($keys['publicKey'], $keys['privateKey']);
            }

            $this->entityManager->persist($instance);

            $admin = new User(
                $pseudo,
                '' === trim($displayName) ? $pseudo : mb_substr(trim($displayName), 0, 64),
                $this->pinHasher->hash($pin),
                $now,
                isAdmin: true,
                email: null !== $email && '' !== trim($email) ? trim($email) : null,
            );
            $this->entityManager->persist($admin);

            $group = new Conversation(ConversationType::Group, $now, $name);
            $this->entityManager->persist($group);
            $this->entityManager->flush();

            $device = $this->deviceService->registerBootstrapDevice(
                $admin,
                $enrollment->deviceId,
                $enrollment->ikPub,
                $enrollment->skPub,
                $enrollment->label,
            );

            $this->membership->join($group, $admin);

            return ['instance' => $instance, 'user' => $admin, 'device' => $device, 'conversation' => $group];
        });
    }
}
