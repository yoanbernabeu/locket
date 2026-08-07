<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\NotFoundException;
use App\Repository\DeviceRepository;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AdminVoter;
use App\Serializer\DevicePresenter;
use App\Serializer\InvitationPresenter;
use App\Service\Admin\MemberRoleService;
use App\Service\Membership\MembershipService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin', format: 'json')]
#[IsGranted(AdminVoter::MANAGE)]
final class AdminController extends AbstractApiController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DeviceRepository $devices,
        private readonly InvitationRepository $invitations,
        private readonly MembershipService $membership,
        private readonly MemberRoleService $roles,
        private readonly DevicePresenter $devicePresenter,
        private readonly InvitationPresenter $invitationPresenter,
    ) {
    }

    #[Route('/overview', name: 'api_admin_overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        $members = [];

        foreach ($this->users->findAllOrderedByPseudo() as $user) {
            $members[] = [
                'id' => $user->getId()->toRfc4122(),
                'pseudo' => $user->getPseudo(),
                'displayName' => $user->getDisplayName(),
                'isAdmin' => $user->isAdmin(),
                'disabledAt' => $user->getDisabledAt()?->format(\DateTimeInterface::ATOM),
                'devices' => $this->devicePresenter->presentAll($this->devices->findByUser($user)),
            ];
        }

        return new JsonResponse([
            'members' => $members,
            'invites' => $this->invitationPresenter->presentAll($this->invitations->findAllOrderedByCreation()),
        ]);
    }

    /**
     * Le refus de désactiver un autre administrateur est porté par AdminVoter, pas par ce
     * contrôleur : la règle doit valoir pour tout appelant, présent ou futur.
     */
    #[Route('/members/{id}/disable', name: 'api_admin_member_disable', methods: ['POST'])]
    public function disableMember(string $id): Response
    {
        $target = $this->users->findOneById($this->parseUuid($id, 'id'))
            ?? throw new NotFoundException('Membre introuvable.');

        $this->denyAccessUnlessGranted(AdminVoter::DISABLE_MEMBER, $target);
        $this->membership->disableMember($target);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Promotion d'un membre au rang d'administrateur (exigence du cahier des charges : une
     * famille doit pouvoir avoir deux parents gestionnaires).
     */
    #[Route('/members/{id}/promote', name: 'api_admin_member_promote', methods: ['POST'])]
    public function promoteMember(string $id): Response
    {
        $target = $this->users->findOneById($this->parseUuid($id, 'id'))
            ?? throw new NotFoundException('Membre introuvable.');

        $this->denyAccessUnlessGranted(AdminVoter::PROMOTE_MEMBER, $target);
        $this->roles->promoteToAdmin($target);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
