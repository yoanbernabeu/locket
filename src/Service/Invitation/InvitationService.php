<?php

declare(strict_types=1);

namespace App\Service\Invitation;

use App\Dto\DeviceEnrollment;
use App\Entity\Device;
use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\InvitationPurpose;
use App\Exception\ConflictException;
use App\Exception\ForbiddenException;
use App\Exception\GoneException;
use App\Exception\InvalidPayloadException;
use App\Exception\NotFoundException;
use App\Repository\DeviceRepository;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Service\Auth\PinHasher;
use App\Service\Device\DeviceService;
use App\Service\Membership\MembershipService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Invitations (PROTOCOL.md §6, API.md §2).
 *
 * Le secret d'invitation ne parvient JAMAIS au serveur : celui-ci ne manipule que son
 * empreinte, calculée par le client. Une fuite de la base ne livre donc aucun lien utilisable.
 */
final class InvitationService
{
    private const string INVITE_ID_HASH_PATTERN = '/\A[0-9a-f]{32}\z/';
    private const int DEFAULT_LIFETIME_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationRepository $invitations,
        private readonly UserRepository $users,
        private readonly DeviceRepository $devices,
        private readonly PinHasher $pinHasher,
        private readonly DeviceService $deviceService,
        private readonly MembershipService $membership,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(
        User $author,
        string $inviteIdHash,
        string $pseudo,
        string $displayName,
        InvitationPurpose $purpose,
        ?Uuid $targetUserId,
    ): Invitation {
        if (1 !== preg_match(self::INVITE_ID_HASH_PATTERN, $inviteIdHash)) {
            throw new InvalidPayloadException('Empreinte d\'invitation invalide : 32 caractères hexadécimaux attendus.');
        }

        $pseudo = trim($pseudo);

        if ('' === $pseudo || mb_strlen($pseudo) > 64) {
            throw new InvalidPayloadException('Le pseudo doit comporter entre 1 et 64 caractères.');
        }

        if (null !== $this->invitations->findOneByInviteIdHash($inviteIdHash)) {
            throw new ConflictException('Cette invitation existe déjà.');
        }

        $target = null;

        if (InvitationPurpose::Recovery === $purpose) {
            if (null === $targetUserId) {
                throw new InvalidPayloadException('Une invitation de récupération doit désigner un membre.');
            }

            $target = $this->users->findOneById($targetUserId) ?? throw new NotFoundException('Membre introuvable.');

            // Une invitation de récupération réécrit le `pinHash` de sa cible et enrôle un
            // appareil en amorçage, sans aucun code à comparer : elle donne donc le contrôle
            // total du compte visé. L'autoriser sur un administrateur permettrait à n'importe
            // quel admin de prendre la place d'un autre — exactement ce que refuse déjà
            // AdminVoter::DISABLE_MEMBER. La règle vit ici, pas dans un contrôleur, pour
            // valoir quel que soit l'appelant.
            if ($target->isAdmin()) {
                throw new ForbiddenException('Un compte administrateur ne peut pas faire l\'objet d\'une invitation de récupération.');
            }
        } elseif (null !== $this->users->findOneByPseudo($pseudo)) {
            throw new ConflictException('Ce pseudo est déjà pris.');
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $invitation = new Invitation(
            $inviteIdHash,
            $pseudo,
            '' === trim($displayName) ? $pseudo : mb_substr(trim($displayName), 0, 64),
            $purpose,
            $author,
            $now,
            $now->add(new \DateInterval('P'.self::DEFAULT_LIFETIME_DAYS.'D')),
            $target,
        );

        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        return $invitation;
    }

    public function revoke(Invitation $invitation): void
    {
        if (null !== $invitation->getRevokedAt() || null !== $invitation->getUsedAt()) {
            return;
        }

        $invitation->revoke(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();
    }

    /**
     * Consomme une invitation et enrôle le premier appareil du compte.
     *
     * La consommation est ATOMIQUE et intervient AVANT toute création : si deux requêtes
     * présentent le même jeton, une seule voit une ligne affectée, l'autre repart en 409.
     * Le tout dans une transaction, pour qu'un échec ultérieur libère aussi l'invitation.
     *
     * @return array{user: User, device: Device}
     */
    public function claim(string $inviteIdHash, string $pin, DeviceEnrollment $enrollment): array
    {
        $this->pinHasher->assertValidPin($pin);
        $this->deviceService->assertValidDeviceId($enrollment->deviceId);

        $invitation = $this->invitations->findOneByInviteIdHash($inviteIdHash)
            ?? throw new NotFoundException('Invitation introuvable.');

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        if ($invitation->getExpiresAt() <= $now) {
            throw new GoneException('Cette invitation a expiré.');
        }

        // Contrôle de courtoisie, pour un message d'erreur exact. Il ne remplace pas la
        // consommation atomique ci-dessous, qui reste seule à faire autorité en concurrence.
        if (null !== $invitation->getRevokedAt()) {
            throw new ConflictException('Cette invitation a été révoquée.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($invitation, $pin, $enrollment, $now): array {
            if (!$this->invitations->consumeAtomically($invitation, $now)) {
                throw new ConflictException('Cette invitation a déjà été utilisée.');
            }

            $user = InvitationPurpose::Recovery === $invitation->getPurpose()
                ? $this->recoverUser($invitation, $pin)
                : $this->createUser($invitation, $pin, $now);

            $device = $this->deviceService->registerBootstrapDevice(
                $user,
                $enrollment->deviceId,
                $enrollment->ikPub,
                $enrollment->skPub,
                $enrollment->label,
            );

            $this->membership->addToGlobalGroup($user);

            return ['user' => $user, 'device' => $device];
        });
    }

    private function createUser(Invitation $invitation, string $pin, \DateTimeImmutable $now): User
    {
        if (null !== $this->users->findOneByPseudo($invitation->getPseudo())) {
            throw new ConflictException('Ce pseudo est déjà pris.');
        }

        $user = new User(
            $invitation->getPseudo(),
            $invitation->getDisplayName(),
            $this->pinHasher->hash($pin),
            $now,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Récupération : le membre repart de zéro. TOUS ses anciens appareils sont révoqués —
     * un téléphone perdu ne doit plus rien recevoir — et il ne retrouvera aucun historique,
     * puisqu'une ancienne clé n'est jamais ré-enveloppée vers un nouvel appareil.
     */
    private function recoverUser(Invitation $invitation, string $pin): User
    {
        $user = $invitation->getTargetUser() ?? throw new NotFoundException('Membre introuvable.');

        // Le contrôle est refait à la CONSOMMATION, pas seulement à la création :
        // une invitation reste valable sept jours, et la cible a pu être promue
        // administratrice entre-temps. Sans cette vérification, il suffisait de
        // préparer une récupération sur un membre ordinaire, de le promouvoir,
        // puis de consommer le lien pour prendre le contrôle de son compte.
        if ($user->isAdmin()) {
            throw new ForbiddenException(
                "La récupération d'un compte administrateur n'est pas possible depuis l'application.",
            );
        }

        foreach ($this->devices->findByUser($user) as $device) {
            $device->revoke();
        }

        $user->setPinHash($this->pinHasher->hash($pin));
        $user->resetFailedAttempts();
        $user->enable();
        $this->entityManager->flush();

        // Révoquer les appareils ne suffit pas : tant que memberSetVersion ne bouge pas,
        // aucun client ne déclenche de rotation, et le téléphone perdu conserve une clé
        // d'époque courante parfaitement vivante. C'est l'incrémentation qui coupe l'accès.
        $this->membership->refreshMemberSetForUser($user);

        return $user;
    }
}
