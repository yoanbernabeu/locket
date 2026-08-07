<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Exception\InvalidPayloadException;
use App\Entity\ConversationKey;
use App\Exception\NotFoundException;
use App\Repository\ConversationRepository;
use App\Repository\UserRepository;
use App\Security\DeviceContext;
use App\Security\Voter\ConversationVoter;
use App\Serializer\ConversationPresenter;
use App\Serializer\RosterPresenter;
use App\Service\Key\ConversationKeyService;
use App\Service\Membership\MembershipService;
use App\Util\Base64Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/conversations', format: 'json')]
final class ConversationController extends AbstractApiController
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly UserRepository $users,
        private readonly MembershipService $membership,
        private readonly ConversationKeyService $keys,
        private readonly ConversationPresenter $presenter,
        private readonly RosterPresenter $rosterPresenter,
        private readonly DeviceContext $deviceContext,
    ) {
    }

    #[Route('', name: 'api_conversations_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->deviceContext->requireUser();
        $this->deviceContext->requireActiveDevice();

        return new JsonResponse($this->presenter->presentAll($this->conversations->findActiveForUser($user)));
    }

    #[Route('/direct', name: 'api_conversations_direct', methods: ['POST'], priority: 1)]
    public function direct(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $peer = $this->users->findOneById($this->parseUuid($this->requiredString($payload, 'userId'), 'userId'))
            ?? throw new NotFoundException('Membre introuvable.');

        $conversation = $this->membership->getOrCreateDirectConversation($this->deviceContext->requireUser(), $peer);

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Route('/{id}/roster', name: 'api_conversations_roster', methods: ['GET'])]
    public function roster(string $id): JsonResponse
    {
        $conversation = $this->loadGranted($id, ConversationVoter::VIEW);

        return new JsonResponse($this->rosterPresenter->present($conversation));
    }

    #[Route('/{id}/keys', name: 'api_conversations_keys_deposit', methods: ['POST'])]
    public function depositKeys(string $id, Request $request): Response
    {
        $conversation = $this->loadGranted($id, ConversationVoter::POST);
        $payload = $this->payload($request);

        $this->keys->deposit(
            $conversation,
            $this->requiredInt($payload, 'epoch'),
            $this->requiredInt($payload, 'memberSetVersion'),
            $this->decodeWraps($payload['wraps'] ?? null),
            $this->decodeAttestation($payload['attestation'] ?? null),
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/keys', name: 'api_conversations_keys_fetch', methods: ['GET'])]
    public function fetchKey(string $id, Request $request): JsonResponse
    {
        $conversation = $this->loadGranted($id, ConversationVoter::VIEW);
        $epoch = $request->query->getInt('epoch', $conversation->getCurrentEpoch());

        $key = $this->keys->findWrap($conversation, $epoch, $this->deviceContext->requireActiveDevice())
            ?? throw new NotFoundException('Aucune clé pour cette époque.');

        // L'ensemble des enveloppes accompagne la réponse : l'attestation porte sur
        // leur empreinte collective, le client ne peut donc pas la vérifier sans elles.
        $wraps = [];
        foreach ($this->keys->findEpochWraps($conversation, $epoch) as $entry) {
            $wraps[] = [
                'recipientDeviceId' => $entry->getRecipientDevice()->getDeviceId(),
                'wrap' => Base64Url::encode($entry->getWrap()),
            ];
        }

        return new JsonResponse([
            'epoch' => $key->getEpoch(),
            'wrap' => Base64Url::encode($key->getWrap()),
            'attestation' => Base64Url::encode($key->getAttestation()),
            'wraps' => $wraps,
        ]);
    }

    /** Une rotation sans attestation n'est pas vérifiable : elle est refusée. */
    private function decodeAttestation(mixed $value): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new InvalidPayloadException("L'attestation de rotation est obligatoire.");
        }

        $decoded = Base64Url::decode($value);
        if (ConversationKey::ATTESTATION_BYTES !== \strlen($decoded)) {
            throw new InvalidPayloadException(
                sprintf('Une attestation fait %d octets.', ConversationKey::ATTESTATION_BYTES),
            );
        }

        return $decoded;
    }

    /**
     * @return list<array{recipientDeviceId: string, wrap: string}>
     */
    private function decodeWraps(mixed $raw): array
    {
        if (!\is_array($raw) || [] === $raw) {
            throw new InvalidPayloadException('Le champ « wraps » est requis.');
        }

        $wraps = [];

        foreach ($raw as $entry) {
            if (!\is_array($entry) || !\is_string($entry['recipientDeviceId'] ?? null) || !\is_string($entry['wrap'] ?? null)) {
                throw new InvalidPayloadException('Chaque wrap doit porter « recipientDeviceId » et « wrap ».');
            }

            $wraps[] = [
                'recipientDeviceId' => $entry['recipientDeviceId'],
                'wrap' => Base64Url::decode($entry['wrap']),
            ];
        }

        return $wraps;
    }

    private function loadGranted(string $id, string $attribute): Conversation
    {
        $conversation = $this->conversations->findOneById($this->parseUuid($id, 'id'))
            ?? throw new NotFoundException('Conversation introuvable.');

        $this->denyAccessUnlessGranted($attribute, $conversation);

        return $conversation;
    }
}
