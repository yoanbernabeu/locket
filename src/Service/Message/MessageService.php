<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Conversation;
use App\Entity\Device;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\MessageType;
use App\Exception\ConflictException;
use App\Exception\ForbiddenException;
use App\Exception\InvalidPayloadException;
use App\Message\NotifyConversation;
use App\Repository\AttachmentRepository;
use App\Repository\ConversationKeyRepository;
use App\Repository\MessageRepository;
use App\Service\Realtime\RealtimePublisher;
use App\Service\RetentionPolicy;
use App\Util\Base64Url;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Écriture et lecture des messages.
 *
 * Le serveur ne déchiffre rien : il valide la forme de l'enveloppe, en extrait le strict
 * nécessaire au routage (type et époque), puis stocke des octets opaques.
 */
final class MessageService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EnvelopeValidator $validator,
        private readonly ConversationKeyRepository $keys,
        private readonly MessageRepository $messages,
        private readonly AttachmentRepository $attachments,
        private readonly RetentionPolicy $retention,
        private readonly RealtimePublisher $realtime,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string       $envelope enveloppe LK1M brute
     * @param list<string> $mediaIds médias à rattacher à cette conversation (voir Attachment)
     */
    public function post(Conversation $conversation, User $sender, Device $senderDevice, string $envelope, array $mediaIds = []): Message
    {
        $header = $this->validator->validate($envelope, $conversation->getId());

        // « keyEpoch connu » (API.md §2) : une époque sans aucune clé déposée signifie que
        // l'émetteur n'est pas à jour. On refuse plutôt que d'accepter un message que
        // personne ne pourra ouvrir.
        if (!$this->keys->epochExists($conversation, $header->keyEpoch)) {
            throw new ConflictException('Époque de chiffrement inconnue pour cette conversation.', [
                'currentEpoch' => $conversation->getCurrentEpoch(),
                'memberSetVersion' => $conversation->getMemberSetVersion(),
            ]);
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $expiresAt = $this->retention->expiresAt($now);

        $message = new Message(
            $conversation,
            $sender,
            $senderDevice,
            $header->msgType,
            $header->keyEpoch,
            $envelope,
            $now,
            $expiresAt,
        );

        // Les rattachements sont validés AVANT de programmer l'écriture du message : un refus
        // ne doit laisser aucune insertion en attente dans l'unité de travail.
        $this->linkAttachments($mediaIds, $conversation, $sender, $expiresAt);
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        // Publié après le flush : le numéro de séquence n'existe qu'une fois la ligne écrite,
        // et annoncer un message que la base n'aurait pas accepté n'aurait aucun sens.
        $this->realtime->publishMessage($message);

        if (MessageType::shouldNotify($header->msgType)) {
            $this->bus->dispatch(new NotifyConversation(
                $conversation->getId()->toRfc4122(),
                $senderDevice->getDeviceId(),
                Base64Url::encode($envelope),
                $header->keyEpoch,
            ));
        }

        return $message;
    }

    /**
     * @return array{messages: list<Message>, hasMore: bool}
     */
    public function fetchSince(Conversation $conversation, int $sinceSeq, int $limit): array
    {
        return $this->messages->findSince($conversation, $sinceSeq, $limit);
    }

    /**
     * Rattachement des médias déclarés par l'émetteur.
     *
     * Un média déjà rattaché ne peut pas l'être une seconde fois : `linkTo()` repousse la date
     * d'expiration, si bien qu'un client pourrait maintenir indéfiniment en vie un média que
     * la rétention devrait avoir effacé — en le « rattachant » à un nouveau message toutes les
     * six jours. Un `mediaId` inconnu est refusé plutôt qu'ignoré : sans cela, le message
     * partirait en désignant une pièce jointe que personne ne pourra jamais charger.
     *
     * @param list<string> $mediaIds
     */
    private function linkAttachments(array $mediaIds, Conversation $conversation, User $sender, \DateTimeImmutable $expiresAt): void
    {
        if ([] === $mediaIds) {
            return;
        }

        $mediaIds = array_values(array_unique($mediaIds));
        $attachments = $this->attachments->findByMediaIds($mediaIds);

        if (\count($attachments) !== \count($mediaIds)) {
            throw new InvalidPayloadException('Un média référencé par ce message est introuvable.');
        }

        foreach ($attachments as $attachment) {
            if (!$attachment->getOwner()->getId()->equals($sender->getId())) {
                throw new ForbiddenException('Un média ne peut être rattaché que par celui qui l\'a déposé.');
            }

            if (null !== $attachment->getConversation()) {
                throw new ConflictException('Ce média est déjà rattaché à une conversation.');
            }

            $attachment->linkTo($conversation, $expiresAt);
        }
    }
}
