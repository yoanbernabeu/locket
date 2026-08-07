<?php

declare(strict_types=1);

namespace App\Service\Retention;

use App\Repository\AttachmentRepository;
use App\Repository\ConversationKeyRepository;
use App\Repository\InvitationRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\MessageRepository;
use App\Service\Auth\LockoutPolicy;
use App\Service\Media\MediaStorage;
use App\Service\RetentionPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Purge de rétention.
 *
 * L'horloge est injectée : sans cela, il serait impossible de tester le comportement au bout
 * de sept jours autrement qu'en attendant sept jours.
 *
 * PIÈGE MAJEUR, traité dans ConversationKeyRepository::findPurgeable() : la clé de l'époque
 * COURANTE n'est jamais supprimée, quel que soit son âge. Une conversation silencieuse depuis
 * plus longtemps que la rétention perdrait sinon la seule clé avec laquelle ses membres
 * peuvent encore écrire, et deviendrait définitivement muette.
 */
final class RetentionPurger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageRepository $messages,
        private readonly AttachmentRepository $attachments,
        private readonly ConversationKeyRepository $keys,
        private readonly InvitationRepository $invitations,
        private readonly LoginAttemptRepository $loginAttempts,
        private readonly MediaStorage $storage,
        private readonly RetentionPolicy $retention,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function purge(): PurgeReport
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        // Les messages partent EN PREMIER : c'est leur disparition qui autorise la clé de
        // leur époque à être supprimée à son tour (ConversationKeyRepository::findPurgeable).
        // Dans l'ordre inverse, chaque clé attendrait un tour de purge supplémentaire.
        $messagesDeleted = $this->messages->deleteExpired($now);

        $filesDeleted = 0;
        $expiredAttachments = $this->attachments->findExpired($now);

        foreach ($expiredAttachments as $attachment) {
            // Le fichier part AVANT la ligne : si la suppression en base échouait après coup,
            // il resterait une ligne orpheline (repurgée au tour suivant) plutôt qu'un fichier
            // orphelin que plus rien ne référencerait.
            if ($this->storage->delete($attachment->getMediaId())) {
                ++$filesDeleted;
            }

            $this->entityManager->remove($attachment);
        }

        $keysDeleted = 0;

        foreach ($this->keys->findPurgeable($this->retention->keyCutoff($now)) as $key) {
            $this->entityManager->remove($key);
            ++$keysDeleted;
        }

        $invitationsDeleted = 0;

        foreach ($this->invitations->findAllOrderedByCreation() as $invitation) {
            if ($invitation->getExpiresAt() <= $now || null !== $invitation->getUsedAt()) {
                $this->entityManager->remove($invitation);
                ++$invitationsDeleted;
            }
        }

        // Compteurs d'échecs de connexion devenus inertes : la table est alimentée par
        // n'importe quel pseudo présenté, y compris inventé, et doit donc être bornée.
        $loginAttemptsDeleted = 0;

        foreach ($this->loginAttempts->findStale($now->modify('-'.LockoutPolicy::DECAY_SECONDS.' seconds'), $now) as $attempt) {
            $this->entityManager->remove($attempt);
            ++$loginAttemptsDeleted;
        }

        $this->entityManager->flush();

        $report = new PurgeReport(
            messagesDeleted: $messagesDeleted,
            attachmentsDeleted: \count($expiredAttachments),
            mediaFilesDeleted: $filesDeleted,
            conversationKeysDeleted: $keysDeleted,
            invitationsDeleted: $invitationsDeleted,
            loginAttemptsDeleted: $loginAttemptsDeleted,
        );

        if (!$report->isEmpty()) {
            $this->logger->info('Purge de rétention effectuée.', (array) $report);
        }

        return $report;
    }
}
