<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Exception\NotFoundException;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Security\DeviceContext;
use App\Security\Voter\ConversationVoter;
use App\Serializer\MessagePresenter;
use App\Service\Message\MessageService;
use App\Util\Base64Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/conversations/{id}/messages', format: 'json')]
final class MessageController extends AbstractApiController
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly MessageService $messages,
        private readonly MessagePresenter $presenter,
        private readonly DeviceContext $deviceContext,
    ) {
    }

    #[Route('', name: 'api_messages_list', methods: ['GET'])]
    public function list(string $id, Request $request): JsonResponse
    {
        $conversation = $this->loadGranted($id, ConversationVoter::VIEW);

        $page = $this->messages->fetchSince(
            $conversation,
            max(0, $request->query->getInt('since')),
            $request->query->getInt('limit', MessageRepository::DEFAULT_LIMIT),
        );

        return new JsonResponse([
            'messages' => $this->presenter->presentAll($page['messages']),
            'hasMore' => $page['hasMore'],
        ]);
    }

    #[Route('', name: 'api_messages_post', methods: ['POST'])]
    public function post(string $id, Request $request): JsonResponse
    {
        $conversation = $this->loadGranted($id, ConversationVoter::POST);
        $payload = $this->payload($request);

        $message = $this->messages->post(
            $conversation,
            $this->deviceContext->requireUser(),
            $this->deviceContext->requireActiveDevice(),
            Base64Url::decode($this->requiredString($payload, 'envelope')),
            $this->stringList($payload, 'mediaIds'),
        );

        return new JsonResponse([
            'id' => $message->getId()->toRfc4122(),
            'seq' => $message->getServerSeq(),
            'createdAt' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $message->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    private function loadGranted(string $id, string $attribute): Conversation
    {
        $conversation = $this->conversations->findOneById($this->parseUuid($id, 'id'))
            ?? throw new NotFoundException('Conversation introuvable.');

        $this->denyAccessUnlessGranted($attribute, $conversation);

        return $conversation;
    }
}
