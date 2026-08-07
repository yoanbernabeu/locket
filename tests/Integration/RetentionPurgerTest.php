<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Conversation;
use App\Entity\Device;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\AttachmentRepository;
use App\Repository\ConversationKeyRepository;
use App\Repository\InvitationRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\MessageRepository;
use App\Service\Media\MediaService;
use App\Service\Media\MediaStorage;
use App\Service\Retention\RetentionPurger;
use App\Service\RetentionPolicy;
use App\Tests\Support\DomainTestCase;
use App\Tests\Support\EnvelopeFactory;
use App\Tests\Support\Streams;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class RetentionPurgerTest extends DomainTestCase
{
    private Conversation $group;
    private User $admin;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $installed = $this->fixtures->install();
        $this->group = $installed['conversation'];
        $this->admin = $installed['user'];
        $this->device = $installed['device'];
    }

    #[TestDox('la purge supprime les messages expirés mais conserve la clé de l\'époque courante')]
    public function testCurrentEpochKeyIsNeverPurged(): void
    {
        $epoch = $this->fixtures->rotateKeys($this->group);
        $this->postMessage($epoch);

        $keys = self::getContainer()->get(ConversationKeyRepository::class);
        \assert($keys instanceof ConversationKeyRepository);
        self::assertTrue($keys->epochExists($this->group, $epoch));

        // Un mois plus tard : tout est plus vieux que la rétention de sept jours.
        $report = $this->purgerAt('2036-01-01 00:00:00')->purge();
        $this->entityManager->clear();

        $messages = self::getContainer()->get(MessageRepository::class);
        \assert($messages instanceof MessageRepository);

        self::assertSame(1, $report->messagesDeleted);
        self::assertSame(0, $messages->lastSeq($this->fixtures->globalGroup()));

        // L'INVARIANT : sans cette clé, la conversation serait devenue définitivement muette.
        self::assertSame(0, $report->conversationKeysDeleted);
        self::assertTrue($keys->epochExists($this->fixtures->globalGroup(), $epoch));
    }

    #[TestDox('la purge supprime les clés des époques révolues')]
    public function testObsoleteEpochKeysArePurged(): void
    {
        $firstEpoch = $this->fixtures->rotateKeys($this->group);
        $this->fixtures->addMember('mamie');
        $this->entityManager->refresh($this->group);
        $secondEpoch = $this->fixtures->rotateKeys($this->group);

        self::assertNotSame($firstEpoch, $secondEpoch);

        $report = $this->purgerAt('2036-01-01 00:00:00')->purge();
        $this->entityManager->clear();

        $keys = self::getContainer()->get(ConversationKeyRepository::class);
        \assert($keys instanceof ConversationKeyRepository);
        $group = $this->fixtures->globalGroup();

        self::assertGreaterThan(0, $report->conversationKeysDeleted);
        self::assertFalse($keys->epochExists($group, $firstEpoch));
        self::assertTrue($keys->epochExists($group, $secondEpoch));
    }

    #[TestDox('C24 : la clé d\'une époque révolue survit tant qu\'un message de cette époque est vivant')]
    public function testKeyOutlivesItsAgeWhileMessagesOfThatEpochRemain(): void
    {
        $oldEpoch = $this->fixtures->rotateKeys($this->group);
        $this->postMessage($oldEpoch);

        // L'époque n'est plus la courante : seule la troisième condition de findPurgeable()
        // peut encore la protéger.
        $this->fixtures->addMember('mamie');
        $this->entityManager->refresh($this->group);
        $currentEpoch = $this->fixtures->rotateKeys($this->group);
        self::assertNotSame($oldEpoch, $currentEpoch);

        // La clé est vieillie de dix jours : elle date d'avant le message qu'elle déchiffre,
        // exactement le cas où purger sur le seul âge de la clé rendrait ce message illisible.
        $this->ageKey($oldEpoch, '-10 days');

        $keys = self::getContainer()->get(ConversationKeyRepository::class);
        \assert($keys instanceof ConversationKeyRepository);

        $report = $this->purgerAt('+1 day')->purge();
        $this->entityManager->clear();

        self::assertSame(0, $report->messagesDeleted, 'Le message n\'a pas encore expiré.');
        self::assertSame(0, $report->conversationKeysDeleted);
        self::assertTrue($keys->epochExists($this->fixtures->globalGroup(), $oldEpoch));
    }

    #[TestDox('C24 : la clé d\'une époque révolue est purgée dès que son dernier message a expiré')]
    public function testKeyIsPurgedOnceItsLastMessageExpired(): void
    {
        $oldEpoch = $this->fixtures->rotateKeys($this->group);
        $this->postMessage($oldEpoch);

        $this->fixtures->addMember('mamie');
        $this->entityManager->refresh($this->group);
        $this->fixtures->rotateKeys($this->group);
        $this->ageKey($oldEpoch, '-10 days');

        $keys = self::getContainer()->get(ConversationKeyRepository::class);
        \assert($keys instanceof ConversationKeyRepository);

        // Huit jours plus tard, le message a dépassé la rétention de sept jours.
        $report = $this->purgerAt('+8 days')->purge();
        $this->entityManager->clear();

        self::assertSame(1, $report->messagesDeleted);
        self::assertGreaterThan(0, $report->conversationKeysDeleted);
        self::assertFalse($keys->epochExists($this->fixtures->globalGroup(), $oldEpoch));
    }

    #[TestDox('la purge supprime aussi les fichiers médias sur le disque')]
    public function testExpiredMediaFilesAreRemovedFromDisk(): void
    {
        $media = self::getContainer()->get(MediaService::class);
        \assert($media instanceof MediaService);

        $storage = self::getContainer()->get(MediaStorage::class);
        \assert($storage instanceof MediaStorage);

        $attachment = $media->store($this->admin, Streams::of(random_bytes(2048)));
        $mediaId = $attachment->getMediaId();

        self::assertTrue($storage->exists($mediaId));
        self::assertFileExists($storage->absolutePath($storage->relativePath($mediaId)));

        // Deux heures plus tard : le média n'a jamais été rattaché à un message.
        $report = $this->purgerAt('+2 hours')->purge();
        $this->entityManager->clear();

        $attachments = self::getContainer()->get(AttachmentRepository::class);
        \assert($attachments instanceof AttachmentRepository);

        self::assertSame(1, $report->attachmentsDeleted);
        self::assertSame(1, $report->mediaFilesDeleted);
        self::assertNull($attachments->findOneByMediaId($mediaId));
        self::assertFalse($storage->exists($mediaId));
        self::assertFileDoesNotExist($storage->absolutePath($storage->relativePath($mediaId)));
    }

    #[TestDox('un média encore rattaché à un message vivant n\'est pas purgé')]
    public function testLinkedMediaSurvivesUntilItsMessageExpires(): void
    {
        $media = self::getContainer()->get(MediaService::class);
        \assert($media instanceof MediaService);

        $storage = self::getContainer()->get(MediaStorage::class);
        \assert($storage instanceof MediaStorage);

        $epoch = $this->fixtures->rotateKeys($this->group);
        $attachment = $media->store($this->admin, Streams::of(random_bytes(1024)));
        $this->postMessage($epoch, [$attachment->getMediaId()]);

        $report = $this->purgerAt('+2 hours')->purge();

        self::assertSame(0, $report->attachmentsDeleted);
        self::assertTrue($storage->exists($attachment->getMediaId()));
    }

    #[TestDox('la purge nettoie les invitations consommées ou expirées')]
    public function testUsedInvitationsArePurged(): void
    {
        $invitations = self::getContainer()->get(\App\Service\Invitation\InvitationService::class);
        \assert($invitations instanceof \App\Service\Invitation\InvitationService);

        $hash = bin2hex(random_bytes(16));
        $invitations->create($this->admin, $hash, 'mamie', 'Mamie', \App\Enum\InvitationPurpose::NewMember, null);

        $report = $this->purgerAt('2036-01-01 00:00:00')->purge();
        $this->entityManager->clear();

        $repository = self::getContainer()->get(InvitationRepository::class);
        \assert($repository instanceof InvitationRepository);

        self::assertSame(1, $report->invitationsDeleted);
        self::assertNull($repository->findOneByInviteIdHash($hash));
    }

    /**
     * Purge datée : l'horloge injectée permet de se placer après la rétention sans attendre.
     * `$moment` accepte aussi bien une date absolue qu'un décalage relatif (« +2 hours »),
     * que le constructeur de DateTimeImmutable interprète dans les deux cas.
     */
    private function purgerAt(string $moment): RetentionPurger
    {
        $container = self::getContainer();

        $messages = $container->get(MessageRepository::class);
        $attachments = $container->get(AttachmentRepository::class);
        $keys = $container->get(ConversationKeyRepository::class);
        $invitations = $container->get(InvitationRepository::class);
        $loginAttempts = $container->get(LoginAttemptRepository::class);
        $storage = $container->get(MediaStorage::class);
        $policy = $container->get(RetentionPolicy::class);

        \assert($messages instanceof MessageRepository);
        \assert($attachments instanceof AttachmentRepository);
        \assert($keys instanceof ConversationKeyRepository);
        \assert($invitations instanceof InvitationRepository);
        \assert($loginAttempts instanceof LoginAttemptRepository);
        \assert($storage instanceof MediaStorage);
        \assert($policy instanceof RetentionPolicy);

        return new RetentionPurger(
            $this->entityManager,
            $messages,
            $attachments,
            $keys,
            $invitations,
            $loginAttempts,
            $storage,
            $policy,
            new MockClock(new \DateTimeImmutable($moment)),
            new NullLogger(),
        );
    }

    /**
     * Vieillit artificiellement les clés d'une époque. L'âge d'une clé et celui des messages
     * qu'elle déchiffre sont indépendants en production : une clé posée lundi sert encore un
     * message écrit samedi. Sans ce décalage, le test ne distinguerait pas les deux dates.
     */
    private function ageKey(int $epoch, string $shift): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE conversation_key SET created_at = :date WHERE epoch = :epoch',
            [
                'date' => (new \DateTimeImmutable($shift))->format('Y-m-d H:i:s'),
                'epoch' => $epoch,
            ],
        );
        $this->entityManager->clear();
    }

    /**
     * @param list<string> $mediaIds
     */
    private function postMessage(int $epoch, array $mediaIds = []): Message
    {
        $service = self::getContainer()->get(\App\Service\Message\MessageService::class);
        \assert($service instanceof \App\Service\Message\MessageService);

        return $service->post(
            $this->group,
            $this->admin,
            $this->device,
            EnvelopeFactory::envelope($this->group->getId(), $epoch),
            $mediaIds,
        );
    }
}
