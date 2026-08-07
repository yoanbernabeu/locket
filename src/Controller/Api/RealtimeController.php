<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\DeviceContext;
use App\Service\Realtime\SubscriberTokenFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/realtime', format: 'json')]
final class RealtimeController extends AbstractApiController
{
    public function __construct(
        private readonly SubscriberTokenFactory $tokens,
        private readonly DeviceContext $deviceContext,
        #[Autowire('%env(default::MERCURE_PUBLIC_URL)%')]
        private readonly string $hubUrl,
    ) {
    }

    /**
     * Fournit au client son JWT d'abonnement Mercure, restreint à ses seuls topics.
     * Le jeton n'est jamais fabriqué côté navigateur : il porte la liste d'autorisations.
     */
    #[Route('/token', name: 'api_realtime_token', methods: ['GET'])]
    public function token(): JsonResponse
    {
        $device = $this->deviceContext->requireActiveDevice();

        return new JsonResponse([
            'hubUrl' => $this->hubUrl,
            'token' => $this->tokens->create($device),
            'topics' => $this->tokens->allowedTopics($device),
        ]);
    }
}
