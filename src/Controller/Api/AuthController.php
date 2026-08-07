<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\DeviceStatus;
use App\Security\Attribute\AllowDeviceStatus;
use App\Security\DeviceContext;
use App\Serializer\DevicePresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[Route('/api', format: 'json')]
final class AuthController extends AbstractApiController
{
    /**
     * Point d'entrée interceptu par App\Security\PinAuthenticator : cette action ne s'exécute
     * jamais. La route existe pour que le pare-feu ait une cible identifiable.
     */
    #[Route('/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('Cette action est prise en charge par l\'authenticator.');
    }

    #[Route('/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    #[AllowDeviceStatus(DeviceStatus::Pending, DeviceStatus::Revoked)]
    public function logout(Request $request, TokenStorageInterface $tokenStorage): Response
    {
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->invalidate();
        }

        $tokenStorage->setToken(null);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    #[AllowDeviceStatus(DeviceStatus::Pending, DeviceStatus::Revoked)]
    public function me(DeviceContext $deviceContext, DevicePresenter $devicePresenter): JsonResponse
    {
        $user = $deviceContext->requireUser();
        $device = $deviceContext->getDevice();

        return new JsonResponse([
            'id' => $user->getId()->toRfc4122(),
            'pseudo' => $user->getPseudo(),
            'displayName' => $user->getDisplayName(),
            'isAdmin' => $user->isAdmin(),
            'device' => null === $device ? null : $devicePresenter->present($device),
        ]);
    }
}
