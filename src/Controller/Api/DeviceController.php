<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\DeviceEnrollment;
use App\Enum\DeviceStatus;
use App\Exception\NotFoundException;
use App\Repository\DeviceRepository;
use App\Security\Attribute\AllowDeviceStatus;
use App\Security\DeviceContext;
use App\Serializer\DevicePresenter;
use App\Service\Device\DeviceService;
use App\Util\Base64Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/devices', format: 'json')]
final class DeviceController extends AbstractApiController
{
    public function __construct(
        private readonly DeviceContext $deviceContext,
        private readonly DeviceRepository $devices,
        private readonly DeviceService $deviceService,
        private readonly DevicePresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_devices_list', methods: ['GET'])]
    #[AllowDeviceStatus(DeviceStatus::Pending)]
    public function list(): JsonResponse
    {
        return new JsonResponse([
            'devices' => $this->presenter->presentAll($this->devices->findByUser($this->deviceContext->requireUser())),
        ]);
    }

    #[Route('', name: 'api_devices_register', methods: ['POST'])]
    #[AllowDeviceStatus(DeviceStatus::Pending)]
    public function register(Request $request): JsonResponse
    {
        $enrollment = DeviceEnrollment::fromPayload($this->payload($request));

        $device = $this->deviceService->register(
            $this->deviceContext->requireUser(),
            $enrollment->deviceId,
            $enrollment->ikPub,
            $enrollment->skPub,
            $enrollment->label,
        );

        return new JsonResponse($this->presenter->present($device), Response::HTTP_CREATED);
    }

    #[Route('/{deviceId}/approve', name: 'api_devices_approve', methods: ['POST'])]
    public function approve(string $deviceId, Request $request): JsonResponse
    {
        $target = $this->devices->findOneByDeviceId($deviceId) ?? throw new NotFoundException('Appareil introuvable.');
        $payload = $this->payload($request);
        $certificate = $this->optionalString($payload, 'approvalCert');

        $this->deviceService->approve(
            $target,
            $this->deviceContext->requireActiveDevice(),
            null === $certificate ? null : Base64Url::decode($certificate),
        );

        return new JsonResponse($this->presenter->present($target));
    }

    #[Route('/{deviceId}', name: 'api_devices_revoke', methods: ['DELETE'])]
    #[AllowDeviceStatus(DeviceStatus::Pending)]
    public function revoke(string $deviceId): Response
    {
        $device = $this->devices->findOneByDeviceId($deviceId) ?? throw new NotFoundException('Appareil introuvable.');

        // Les règles (propriété de l'appareil, auto-révocation seule pour un appareil en
        // attente) vivent dans DeviceService : elles doivent valoir pour tout appelant.
        $this->deviceService->revokeAs($device, $this->deviceContext->requireDevice());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
