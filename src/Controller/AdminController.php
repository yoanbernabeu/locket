<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DeviceRepository;
use App\Repository\InstanceRepository;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran d'administration.
 *
 * Contrairement à la messagerie, ces données ne sont pas chiffrées : ce sont des
 * pseudos et des états de compte. Twig est donc pleinement adapté ici, et évite
 * de réécrire en JavaScript des pages que le serveur rend très bien.
 */
#[Route('/admin')]
#[IsGranted('LOCKET_ADMIN')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly InvitationRepository $invitations,
        private readonly DeviceRepository $devices,
        private readonly InstanceRepository $instances,
    ) {
    }

    #[Route('', name: 'admin_index', methods: ['GET'])]
    public function index(): Response
    {
        $membres = [];
        foreach ($this->users->findAllOrderedByPseudo() as $user) {
            if (null !== $user->getDisabledAt()) {
                continue;
            }

            $membres[] = [
                'id' => $user->getId()->toRfc4122(),
                'pseudo' => $user->getPseudo(),
                'displayName' => $user->getDisplayName(),
                'isAdmin' => $user->isAdmin(),
                'deviceCount' => \count($this->devices->findActiveByUser($user)),
            ];
        }

        $invitationsEnAttente = [];
        foreach ($this->invitations->findAllOrderedByCreation() as $invitation) {
            if (null !== $invitation->getUsedAt() || null !== $invitation->getRevokedAt()) {
                continue;
            }

            $invitationsEnAttente[] = [
                'id' => $invitation->getId()->toRfc4122(),
                'displayName' => $invitation->getDisplayName(),
                'expiresAt' => $invitation->getExpiresAt(),
            ];
        }

        return $this->render('admin/index.html.twig', [
            'instance_name' => $this->instances->get()?->getName() ?? 'Locket',
            'membres' => $membres,
            'invitations' => $invitationsEnAttente,
        ]);
    }
}
