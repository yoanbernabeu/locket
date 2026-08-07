<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Device;
use App\Entity\User;
use App\Service\Membership\MembershipService;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnvelopeFactory;
use App\Util\Base64Url;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class MessageFlowTest extends ApiTestCase
{
    private User $admin;
    private Device $adminDevice;
    private Conversation $group;
    private int $epoch;

    /** @var array{user: User, device: Device} */
    private array $mamie;

    protected function setUp(): void
    {
        parent::setUp();

        $installed = $this->fixtures->install('papa', '123456');
        $this->admin = $installed['user'];
        $this->adminDevice = $installed['device'];
        $this->group = $installed['conversation'];

        $this->mamie = $this->fixtures->addMember('mamie', '654321');
        $this->entityManager->refresh($this->group);
        $this->epoch = $this->fixtures->rotateKeys($this->group);
    }

    #[TestDox('un membre actif publie un message et le relit par synchronisation incrémentale')]
    public function testPostAndSync(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->postEnvelope(EnvelopeFactory::envelope($this->group->getId(), $this->epoch));

        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());
        $created = $this->json();
        self::assertSame(1, $created['seq']);

        $this->request('GET', $this->messagesUri().'?since=0&limit=200');

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        $page = $this->json();
        self::assertCount(1, $page['messages']);
        self::assertFalse($page['hasMore']);
        self::assertSame('papa', $page['messages'][0]['senderPseudo']);
        self::assertSame($created['id'], $page['messages'][0]['id']);

        // Rien de neuf au-delà du dernier numéro de séquence connu.
        $this->request('GET', $this->messagesUri().'?since='.$created['seq']);
        self::assertSame([], $this->json()['messages']);
    }

    #[TestDox('un membre retiré ne peut plus poster dans une conversation')]
    public function testRemovedMemberCannotPost(): void
    {
        $this->login('mamie', '654321', $this->mamie['device']->getDeviceId());

        $this->postEnvelope(EnvelopeFactory::envelope($this->group->getId(), $this->epoch));
        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());

        $membership = self::getContainer()->get(MembershipService::class);
        \assert($membership instanceof MembershipService);
        $membership->leave($this->fixtures->globalGroup(), $this->reloadMamie());

        $this->postEnvelope(EnvelopeFactory::envelope($this->group->getId(), $this->epoch));
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());

        // La lecture tombe elle aussi : l'historique ne suit pas un membre retiré.
        $this->request('GET', $this->messagesUri());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());
    }

    #[TestDox('une enveloppe dont le convId ne correspond pas à l\'URL est rejetée')]
    public function testEnvelopeWithForeignConversationIdIsRejected(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->postEnvelope(EnvelopeFactory::envelope(Uuid::v7(), $this->epoch));

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->httpStatus());
        self::assertStringContainsString('identifiant de conversation', $this->json()['error']);
    }

    #[TestDox('une enveloppe trop courte est rejetée')]
    public function testTooShortEnvelopeIsRejected(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->postEnvelope(random_bytes(20));

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->httpStatus());
    }

    #[TestDox('un message dont l\'époque est inconnue du serveur reçoit un 409')]
    public function testUnknownEpochConflicts(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->postEnvelope(EnvelopeFactory::envelope($this->group->getId(), $this->epoch + 5));

        self::assertSame(Response::HTTP_CONFLICT, $this->httpStatus());
        self::assertArrayHasKey('currentEpoch', $this->json());
    }

    #[TestDox('un non-membre ne peut ni lire ni écrire dans une conversation directe')]
    public function testNonMemberIsRejectedFromDirectConversation(): void
    {
        $tonton = $this->fixtures->addMember('tonton', '111222');

        $membership = self::getContainer()->get(MembershipService::class);
        \assert($membership instanceof MembershipService);
        $direct = $membership->getOrCreateDirectConversation($this->admin, $this->reloadMamie());

        $this->login('tonton', '111222', $tonton['device']->getDeviceId());
        $this->request('GET', '/api/conversations/'.$direct->getId()->toRfc4122().'/messages');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());
    }

    #[TestDox('la liste des conversations expose le dernier numéro de séquence')]
    public function testConversationListExposesLastSeq(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->postEnvelope(EnvelopeFactory::envelope($this->group->getId(), $this->epoch));

        $this->request('GET', '/api/conversations');

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        $conversations = $this->json();
        self::assertCount(1, $conversations);
        self::assertSame('group', $conversations[0]['type']);
        self::assertSame(1, $conversations[0]['lastSeq']);
        self::assertCount(2, $conversations[0]['members']);
    }

    private function messagesUri(): string
    {
        return '/api/conversations/'.$this->group->getId()->toRfc4122().'/messages';
    }

    private function postEnvelope(string $envelope): void
    {
        $this->request('POST', $this->messagesUri(), ['envelope' => Base64Url::encode($envelope)]);
    }

    private function reloadMamie(): User
    {
        return $this->users()->findOneByPseudo('mamie') ?? throw new \RuntimeException('Membre absent.');
    }
}
