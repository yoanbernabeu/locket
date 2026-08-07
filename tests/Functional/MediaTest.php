<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Attachment;
use App\Entity\Conversation;
use App\Entity\Device;
use App\Exception\GoneException;
use App\Exception\TooManyRequestsException;
use App\Repository\AttachmentRepository;
use App\Service\Media\MediaService;
use App\Service\Media\MediaStorage;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnvelopeFactory;
use App\Tests\Support\Streams;
use App\Util\Base64Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

final class MediaTest extends ApiTestCase
{
    private Device $adminDevice;
    private Conversation $group;
    private int $epoch;

    /** @var array{user: \App\Entity\User, device: Device} */
    private array $mamie;

    protected function setUp(): void
    {
        parent::setUp();

        $installed = $this->fixtures->install('papa', '123456');
        $this->adminDevice = $installed['device'];
        $this->group = $installed['conversation'];

        $this->mamie = $this->fixtures->addMember('mamie', '654321');
        $this->entityManager->refresh($this->group);
        $this->epoch = $this->fixtures->rotateKeys($this->group);
    }

    #[TestDox('un média déposé se relit octet pour octet')]
    public function testUploadAndDownload(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $blob = random_bytes(4096);
        $mediaId = $this->upload($blob);

        $this->client->request('GET', '/api/media/'.$mediaId);

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame('application/octet-stream', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame($blob, $this->downloadedBytes());
    }

    #[TestDox('un média non rattaché reste privé à son déposant')]
    public function testUnlinkedMediaIsPrivate(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $mediaId = $this->upload(random_bytes(256));

        $this->logout();
        $this->login('mamie', '654321', $this->mamie['device']->getDeviceId());
        $this->client->request('GET', '/api/media/'.$mediaId);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());
    }

    #[TestDox('un média rattaché à une conversation devient lisible par ses membres')]
    public function testLinkedMediaIsReadableByConversationMembers(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $blob = random_bytes(512);
        $mediaId = $this->upload($blob);

        $this->request('POST', '/api/conversations/'.$this->group->getId()->toRfc4122().'/messages', [
            'envelope' => Base64Url::encode(EnvelopeFactory::envelope($this->group->getId(), $this->epoch, msgType: 0x02)),
            'mediaIds' => [$mediaId],
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());

        $this->logout();
        $this->login('mamie', '654321', $this->mamie['device']->getDeviceId());
        $this->client->request('GET', '/api/media/'.$mediaId);

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame($blob, $this->downloadedBytes());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileMediaIds(): iterable
    {
        yield 'caractère hors alphabet hexadécimal' => ['0123456789abcdef0123456789abcdeZ'];
        yield 'majuscules' => ['0123456789ABCDEF0123456789ABCDEF'];
        yield 'trop court' => ['0123'];
    }

    #[DataProvider('hostileMediaIds')]
    #[TestDox('un identifiant de média hostile est refusé par un 400, sans jamais toucher au disque')]
    public function testHostileMediaIdIsRejected(string $mediaId): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->client->request('GET', '/api/media/'.$mediaId);

        // Un code unique et prévisible : accepter « 400 ou 404 » revenait à ne rien vérifier,
        // et aurait laissé passer un 200 sur /composer.json transformé en 404 par la suite.
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->httpStatus());
    }

    #[TestDox('une traversée de répertoire encodée n\'atteint jamais le contrôleur de médias')]
    public function testEncodedPathTraversalNeverReachesTheController(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        // Le %2F est décodé avant la mise en correspondance des routes : le chemin comporte
        // alors des séparateurs, et le motif {mediaId} ne peut plus correspondre. Le 404 vient
        // donc du routeur, pas du contrôleur — ce qui est la meilleure des issues.
        $this->client->request('GET', '/api/media/'.rawurlencode('../../../composer.json'));

        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
        self::assertStringNotContainsString('autoload', (string) $this->client->getInternalResponse()->getContent());
    }

    #[TestDox('C16 : un média est refusé sur son Content-Length, sans lire le corps')]
    public function testOversizedUploadIsRejectedOnContentLength(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        // Le corps envoyé est minuscule : c'est bien l'en-tête déclaré qui doit provoquer le
        // refus, avant que le moindre octet ne soit écrit sur le disque.
        $this->client->request(
            'POST',
            '/api/media',
            server: [
                'CONTENT_TYPE' => 'application/octet-stream',
                'CONTENT_LENGTH' => (string) (Attachment::MAX_BYTES + 1),
            ],
            content: 'x',
        );

        self::assertSame(Response::HTTP_GONE, $this->httpStatus());

        $attachments = self::getContainer()->get(AttachmentRepository::class);
        \assert($attachments instanceof AttachmentRepository);
        self::assertCount(0, $attachments->findAll());
    }

    #[TestDox('C16 : un corps plus gros que le Content-Length annoncé est refusé pendant la copie')]
    public function testOversizedBodyIsRejectedDuringTheCopy(): void
    {
        $media = self::getContainer()->get(MediaService::class);
        \assert($media instanceof MediaService);

        $user = $this->users()->findOneByPseudo('papa');
        self::assertNotNull($user);

        // Content-Length non déclaré : seule la limite appliquée pendant la copie protège.
        $this->expectException(GoneException::class);

        $media->store($user, Streams::of(str_repeat('a', Attachment::MAX_BYTES + 1)), null);
    }

    #[TestDox('C17 : un membre ne peut pas accumuler indéfiniment de médias non rattachés')]
    public function testUnlinkedQuotaIsEnforced(): void
    {
        $media = self::getContainer()->get(MediaService::class);
        \assert($media instanceof MediaService);

        $storage = self::getContainer()->get(MediaStorage::class);
        \assert($storage instanceof MediaStorage);

        $user = $this->users()->findOneByPseudo('papa');
        self::assertNotNull($user);

        // Le quota est amené à saturation en gonflant la taille déclarée d'un dépôt existant :
        // écrire réellement 100 Mio sur le disque n'apporterait rien à la démonstration.
        $first = $media->store($user, Streams::of(random_bytes(1024)));
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE attachment SET bytes = :bytes WHERE media_id = :mediaId',
            ['bytes' => MediaService::UNLINKED_QUOTA_BYTES, 'mediaId' => $first->getMediaId()],
        );
        $this->entityManager->clear();

        $before = glob($storage->absolutePath('*/*/*')) ?: [];

        try {
            $media->store($this->users()->findOneByPseudo('papa'), Streams::of(random_bytes(2048)));
            self::fail('Le quota aurait dû être opposé.');
        } catch (TooManyRequestsException $exception) {
            self::assertStringContainsString('en attente d\'envoi', $exception->getMessage());
        }

        // Le blob refusé ne doit pas rester sur le disque : sans ce nettoyage, le quota
        // deviendrait un simple ralentisseur au lieu d'une limite.
        self::assertSame($before, glob($storage->absolutePath('*/*/*')) ?: []);
    }

    #[TestDox('C23 : un média déjà rattaché ne peut pas l\'être une seconde fois')]
    public function testAlreadyLinkedMediaCannotBeRelinked(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $mediaId = $this->upload(random_bytes(256));

        $this->postMessageWithMedia($mediaId);
        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());

        // Chaque rattachement repousse la date d'expiration : sans ce refus, un client garde
        // un média en vie indéfiniment en le « rattachant » à un nouveau message chaque jour,
        // et la rétention de sept jours devient une promesse creuse.
        $this->postMessageWithMedia($mediaId);
        self::assertSame(Response::HTTP_CONFLICT, $this->httpStatus());
    }

    #[TestDox('C23 : un mediaId inconnu fait échouer l\'envoi du message')]
    public function testUnknownMediaIdIsRejected(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        // Auparavant l'identifiant était silencieusement ignoré : le message partait en
        // désignant une pièce jointe que personne ne pourrait jamais charger.
        $this->postMessageWithMedia(bin2hex(random_bytes(16)));

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->httpStatus());
    }

    #[TestDox('un média inconnu renvoie 404')]
    public function testUnknownMediaIsNotFound(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->client->request('GET', '/api/media/'.bin2hex(random_bytes(16)));

        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
    }

    /**
     * Le média est servi par BinaryFileResponse, dont `getContent()` renvoie `false` : le
     * corps n'existe qu'au moment de l'envoi. La réponse « interne » de BrowserKit, elle,
     * contient bien les octets effectivement émis.
     */
    private function downloadedBytes(): string
    {
        return $this->client->getInternalResponse()->getContent();
    }

    private function postMessageWithMedia(string $mediaId): void
    {
        $this->request('POST', '/api/conversations/'.$this->group->getId()->toRfc4122().'/messages', [
            'envelope' => Base64Url::encode(EnvelopeFactory::envelope($this->group->getId(), $this->epoch, msgType: 0x02)),
            'mediaIds' => [$mediaId],
        ]);
    }

    private function upload(string $blob): string
    {
        $this->client->request('POST', '/api/media', server: ['CONTENT_TYPE' => 'application/octet-stream'], content: $blob);

        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());

        return $this->json()['mediaId'];
    }
}
