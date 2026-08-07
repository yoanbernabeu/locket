<?php

declare(strict_types=1);

namespace App\Service\Membership;

use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Exception\ConflictException;
use App\Exception\ForbiddenException;
use App\Exception\InvalidPayloadException;
use App\Repository\ConversationMemberRepository;
use App\Repository\ConversationRepository;
use App\Repository\DeviceRepository;
use App\Service\Realtime\RealtimePublisher;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Composition des conversations.
 *
 * INVARIANT CENTRAL : toute modification de composition — membre ajouté, membre retiré,
 * appareil approuvé ou révoqué — incrémente `memberSetVersion`. Cette valeur devient le
 * numéro de la prochaine époque (PROTOCOL.md §4) ; si elle n'était pas incrémentée, deux
 * compositions différentes partageraient une époque, et un membre retiré resterait capable
 * de déchiffrer. Chaque chemin d'écriture de cette classe passe donc par bumpMemberSetVersion().
 */
final class MembershipService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationRepository $conversations,
        private readonly ConversationMemberRepository $members,
        private readonly DeviceRepository $devices,
        private readonly RealtimePublisher $realtime,
        private readonly LockFactory $locks,
        private readonly ClockInterface $clock,
    ) {
    }

    public function join(Conversation $conversation, User $user): ConversationMember
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $membership = $this->members->findMembership($conversation, $user);

        if (null !== $membership && $membership->isActive()) {
            return $membership;
        }

        if (null === $membership) {
            $membership = new ConversationMember($conversation, $user, $now);
            $this->entityManager->persist($membership);
        } else {
            $membership->rejoin($now);
        }

        $conversation->bumpMemberSetVersion();
        $this->entityManager->flush();

        $this->realtime->publishRosterChangedToAll(
            $this->devices->findActiveDevicesForConversation($conversation),
            $conversation,
        );

        return $membership;
    }

    public function leave(Conversation $conversation, User $user): void
    {
        $membership = $this->members->findMembership($conversation, $user);

        if (null === $membership || !$membership->isActive()) {
            return;
        }

        $membership->leave(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $conversation->bumpMemberSetVersion();
        $this->entityManager->flush();

        $this->realtime->publishRosterChangedToAll(
            $this->devices->findActiveDevicesForConversation($conversation),
            $conversation,
        );
    }

    /**
     * Création idempotente d'un 1-1.
     *
     * La course est arbitrée AVANT le flush, par un verrou nommé sur la clé de conversation.
     * Rattraper la UniqueConstraintViolationException ne fonctionne pas : Doctrine FERME
     * l'EntityManager dès qu'une insertion échoue, si bien que la relecture censée récupérer
     * la conversation gagnante lève à son tour, et la requête part en 500. On sérialise donc
     * la fenêtre lecture-écriture, et la contrainte d'unicité ne sert plus que de filet.
     */
    public function getOrCreateDirectConversation(User $a, User $b): Conversation
    {
        if ($a->getId()->equals($b->getId())) {
            throw new InvalidPayloadException('Une conversation directe requiert deux membres distincts.');
        }

        if ($a->isDisabled() || $b->isDisabled()) {
            throw new ForbiddenException('Ce membre ne fait plus partie de la famille.');
        }

        $directKey = Conversation::directKeyFor($a->getId(), $b->getId());
        $existing = $this->conversations->findOneByDirectKey($directKey);

        if (null !== $existing) {
            return $existing;
        }

        $lock = $this->locks->createLock('locket.direct-conversation.'.hash('xxh128', $directKey), 10.0);
        $lock->acquire(true);

        try {
            // Relecture SOUS verrou : si un appel concurrent a créé la conversation entre
            // notre premier test et l'acquisition, on la récupère au lieu d'insérer.
            $existing = $this->conversations->findOneByDirectKey($directKey);

            if (null !== $existing) {
                return $existing;
            }

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $conversation = new Conversation(ConversationType::Direct, $now, null, $directKey);

            $this->entityManager->persist($conversation);
            $this->entityManager->persist(new ConversationMember($conversation, $a, $now));
            $this->entityManager->persist(new ConversationMember($conversation, $b, $now));
            $conversation->bumpMemberSetVersion();
            $this->entityManager->flush();

            return $conversation;
        } catch (UniqueConstraintViolationException) {
            // Filet de sécurité : le verrou est local au processus, la contrainte ne l'est
            // pas. L'EntityManager étant clos, on ne peut plus rien relire ici — un 409
            // demandant de réessayer est la seule réponse honnête, et vaut mieux qu'un 500.
            throw new ConflictException('La conversation directe vient d\'être créée par ailleurs. Réessayez.');
        } finally {
            $lock->release();
        }
    }

    public function addToGlobalGroup(User $user): Conversation
    {
        $group = $this->conversations->findGlobalGroup()
            ?? throw new \RuntimeException('Le groupe global n\'existe pas : l\'instance n\'est pas installée.');

        $this->join($group, $user);

        return $group;
    }

    /**
     * Répercute un changement d'appareil sur toutes les conversations de l'utilisateur.
     * Un nouvel appareil ne doit pas pouvoir lire l'historique : il n'obtiendra une clé qu'à
     * la prochaine époque, déclenchée précisément par cette incrémentation.
     */
    public function refreshMemberSetForUser(User $user): void
    {
        $conversations = $this->conversations->findActiveForUser($user);

        foreach ($conversations as $conversation) {
            $conversation->bumpMemberSetVersion();
        }

        $this->entityManager->flush();

        foreach ($conversations as $conversation) {
            $this->realtime->publishRosterChangedToAll(
                $this->devices->findActiveDevicesForConversation($conversation),
                $conversation,
            );
        }
    }

    /**
     * Retrait complet d'un membre : compte désactivé, appareils révoqués, sorties de toutes
     * les conversations et incrémentation partout. À partir de là il ne reçoit plus de wrap,
     * donc plus aucune époque ultérieure ne lui est lisible.
     */
    public function disableMember(User $target): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $target->disable($now);

        foreach ($this->devices->findByUser($target) as $device) {
            $device->revoke();
        }

        $memberships = $this->members->findActiveMembershipsOfUser($target);

        foreach ($memberships as $membership) {
            $membership->leave($now);
            $membership->getConversation()->bumpMemberSetVersion();
        }

        $this->entityManager->flush();

        foreach ($memberships as $membership) {
            $conversation = $membership->getConversation();
            $this->realtime->publishRosterChangedToAll(
                $this->devices->findActiveDevicesForConversation($conversation),
                $conversation,
            );
        }
    }
}
