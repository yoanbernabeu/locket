<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\DeviceContext;
use App\Service\Push\PushSubscriptionService;
use App\Service\Push\VapidKeyProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/push', format: 'json')]
final class PushController extends AbstractApiController
{
    public function __construct(
        private readonly PushSubscriptionService $subscriptions,
        private readonly DeviceContext $deviceContext,
        private readonly VapidKeyProvider $vapid,
    ) {
    }

    #[Route('/vapid-public-key', name: 'api_push_vapid_key', methods: ['GET'])]
    public function vapidPublicKey(): JsonResponse
    {
        return new JsonResponse(['publicKey' => $this->vapid->getPublicKey()]);
    }

    #[Route('/subscribe', name: 'api_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        $payload = $this->payload($request);

        $this->subscriptions->subscribe(
            $this->deviceContext->requireActiveDevice(),
            $this->requiredString($payload, 'endpoint'),
            $this->requiredString($payload, 'p256dh'),
            $this->requiredString($payload, 'auth'),
        );

        return new JsonResponse(['subscribed' => true], Response::HTTP_CREATED);
    }

    #[Route('/subscribe', name: 'api_push_unsubscribe', methods: ['DELETE'])]
    public function unsubscribe(): Response
    {
        $this->subscriptions->unsubscribe($this->deviceContext->requireActiveDevice());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
