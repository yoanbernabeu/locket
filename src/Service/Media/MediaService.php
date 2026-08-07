<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Attachment;
use App\Entity\User;
use App\Exception\ForbiddenException;
use App\Exception\GoneException;
use App\Exception\NotFoundException;
use App\Exception\TooManyRequestsException;
use App\Repository\AttachmentRepository;
use App\Repository\ConversationMemberRepository;
use App\Service\RetentionPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Dépôt et lecture des blobs médias.
 *
 * LIMITE ASSUMÉE de l'autorisation : le `mediaId` vit dans le clair chiffré du message, donc
 * le serveur ne peut pas déduire seul quelle conversation référence quel média. Le
 * rattachement est déclaré par le client à l'envoi du message ; tant qu'il ne l'est pas, seul
 * le déposant peut relire son blob. Une fois rattaché, la règle d'API.md s'applique
 * pleinement : seul un membre actif de la conversation peut lire.
 */
final class MediaService
{
    /**
     * Volume total de médias déposés mais pas encore rattachés à un message, par membre.
     * Sans ce plafond, un membre remplit le disque de l'instance en une poignée de requêtes :
     * les orphelins ne sont ramassés qu'au bout d'une heure.
     */
    public const int UNLINKED_QUOTA_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AttachmentRepository $attachments,
        private readonly ConversationMemberRepository $members,
        private readonly MediaStorage $storage,
        private readonly RetentionPolicy $retention,
        #[Target('mediaUploadLimiter')]
        private readonly RateLimiterFactoryInterface $uploadLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param resource $source     corps de la requête, lu en flux
     * @param int|null $declaredSize valeur de l'en-tête `Content-Length`, si le client l'a fournie
     */
    public function store(User $owner, $source, ?int $declaredSize = null): Attachment
    {
        $this->assertWithinRate($owner);

        // Refus au plus tôt : inutile d'écrire 200 Mio sur le disque pour découvrir ensuite
        // qu'ils dépassent le plafond. La valeur reste déclarative, d'où le second contrôle
        // pendant la copie (MediaStorage::writeStream).
        if (null !== $declaredSize && $declaredSize > Attachment::MAX_BYTES) {
            throw new GoneException(\sprintf('Média trop volumineux : maximum %d octets.', Attachment::MAX_BYTES));
        }

        $this->assertWithinQuota($owner, $declaredSize ?? 0);

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $mediaId = $this->storage->generateMediaId();
        $written = $this->storage->writeStream($mediaId, $source, Attachment::MAX_BYTES);

        // Le quota est revérifié sur la taille réellement écrite : un client peut mentir sur
        // `Content-Length`, ou ne pas l'envoyer du tout.
        try {
            $this->assertWithinQuota($owner, $written['bytes']);
        } catch (TooManyRequestsException $exception) {
            $this->storage->delete($mediaId);

            throw $exception;
        }

        $attachment = new Attachment(
            $mediaId,
            $owner,
            $written['path'],
            $written['bytes'],
            $now,
            $this->retention->orphanMediaExpiresAt($now),
        );

        $this->entityManager->persist($attachment);
        $this->entityManager->flush();

        return $attachment;
    }

    /**
     * Chemin absolu du blob, une fois l'autorisation vérifiée. Le contrôleur le sert par
     * BinaryFileResponse : charger 25 Mio en mémoire pour les recopier immédiatement dans la
     * réponse n'a aucun intérêt, et fait exactement le contraire de ce que le mode worker exige.
     */
    public function locate(string $mediaId, User $reader): string
    {
        $attachment = $this->authorize($mediaId, $reader);
        $absolute = $this->storage->absolutePath($attachment->getPath());

        if (!is_file($absolute)) {
            throw new NotFoundException('Média introuvable.');
        }

        return $absolute;
    }

    /**
     * Vérifie l'autorisation de lecture. Séparé de locate() pour que la règle reste
     * consultable sans manipuler de chemin de fichier.
     */
    public function authorize(string $mediaId, User $reader): Attachment
    {
        $this->storage->assertValidMediaId($mediaId);

        $attachment = $this->attachments->findOneByMediaId($mediaId)
            ?? throw new NotFoundException('Média introuvable.');

        if ($attachment->getExpiresAt() <= $this->clock->now()) {
            throw new GoneException('Ce média a expiré.');
        }

        $conversation = $attachment->getConversation();

        $authorized = null === $conversation
            ? $attachment->getOwner()->getId()->equals($reader->getId())
            : $this->members->isActiveMember($conversation, $reader);

        if (!$authorized) {
            throw new ForbiddenException('Accès au média refusé.');
        }

        return $attachment;
    }

    private function assertWithinRate(User $owner): void
    {
        $limit = $this->uploadLimiter->create($owner->getId()->toRfc4122())->consume();

        if ($limit->isAccepted()) {
            return;
        }

        throw new TooManyRequestsException(
            'Trop de médias envoyés coup sur coup. Réessayez dans un instant.',
            $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
        );
    }

    private function assertWithinQuota(User $owner, int $additionalBytes): void
    {
        if ($this->attachments->unlinkedBytesOf($owner) + $additionalBytes <= self::UNLINKED_QUOTA_BYTES) {
            return;
        }

        throw new TooManyRequestsException(
            'Trop de médias en attente d\'envoi. Terminez ou abandonnez les envois en cours.',
            RetentionPolicy::ORPHAN_MEDIA_GRACE_SECONDS,
        );
    }
}
