<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\DeviceEnrollment;
use App\Enum\InvitationPurpose;
use App\Exception\InvalidPayloadException;
use App\Exception\NotFoundException;
use App\Repository\InvitationRepository;
use App\Security\DeviceContext;
use App\Security\Voter\AdminVoter;
use App\Serializer\DevicePresenter;
use App\Serializer\InvitationPresenter;
use App\Service\Invitation\InvitationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/invites', format: 'json')]
final class InvitationController extends AbstractApiController
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly InvitationRepository $repository,
        private readonly InvitationPresenter $presenter,
        private readonly DevicePresenter $devicePresenter,
        private readonly DeviceContext $deviceContext,
    ) {
    }

    #[Route('', name: 'api_invites_create', methods: ['POST'])]
    #[IsGranted(AdminVoter::MANAGE)]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $rawPurpose = $this->optionalString($payload, 'purpose', InvitationPurpose::NewMember->value) ?? '';
        $purpose = InvitationPurpose::tryFrom($rawPurpose)
            ?? throw new InvalidPayloadException('Type d\'invitation inconnu.');

        $targetUserId = $this->optionalString($payload, 'targetUserId');

        $invitation = $this->invitations->create(
            $this->deviceContext->requireUser(),
            $this->requiredString($payload, 'inviteIdHash'),
            $this->requiredString($payload, 'pseudo'),
            $this->optionalString($payload, 'displayName', '') ?? '',
            $purpose,
            null === $targetUserId ? null : $this->parseUuid($targetUserId, 'targetUserId'),
        );

        return new JsonResponse($this->presenter->present($invitation), Response::HTTP_CREATED);
    }

    #[Route('', name: 'api_invites_list', methods: ['GET'])]
    #[IsGranted(AdminVoter::MANAGE)]
    public function list(): JsonResponse
    {
        return new JsonResponse(['invites' => $this->presenter->presentAll($this->repository->findAllOrderedByCreation())]);
    }

    #[Route('/{id}', name: 'api_invites_revoke', methods: ['DELETE'])]
    #[IsGranted(AdminVoter::MANAGE)]
    public function revoke(string $id): Response
    {
        $invitation = $this->repository->findOneById($this->parseUuid($id, 'id'))
            ?? throw new NotFoundException('Invitation introuvable.');

        $this->invitations->revoke($invitation);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Endpoint anonyme : c'est le seul chemin par lequel un nouveau membre entre.
     */
    #[Route('/claim', name: 'api_invites_claim', methods: ['POST'], priority: 1)]
    public function claim(Request $request): JsonResponse
    {
        $payload = $this->payload($request);

        $result = $this->invitations->claim(
            $this->requiredString($payload, 'inviteIdHash'),
            $this->requiredString($payload, 'pin'),
            DeviceEnrollment::fromPayload($payload['device'] ?? null),
        );

        return new JsonResponse([
            'user' => [
                'id' => $result['user']->getId()->toRfc4122(),
                'pseudo' => $result['user']->getPseudo(),
                'displayName' => $result['user']->getDisplayName(),
                'isAdmin' => $result['user']->isAdmin(),
            ],
            'device' => $this->devicePresenter->present($result['device']),
        ], Response::HTTP_CREATED);
    }
}
