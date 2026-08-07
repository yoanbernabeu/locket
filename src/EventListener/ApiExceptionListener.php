<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ConflictException;
use App\Exception\ForbiddenException;
use App\Exception\GoneException;
use App\Exception\InvalidPayloadException;
use App\Exception\NotFoundException;
use App\Exception\TooManyRequestsException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Traduction des exceptions du domaine en codes HTTP, en un seul endroit.
 *
 * Les contrôleurs lèvent des exceptions métier lisibles ; la correspondance avec les codes
 * d'API.md §2 (400, 403, 404, 409, 410) vit ici et nulle part ailleurs.
 *
 * Priorité négative : le pare-feu de sécurité doit avoir tranché AVANT nous. C'est lui seul
 * qui sait distinguer « pas encore authentifié » (401, via le point d'entrée) de « authentifié
 * mais non autorisé » (403) ; intercepter AccessDeniedException plus tôt transformerait tous
 * les 401 en 403.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: -16)]
final class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();

        if (!str_starts_with($path, '/api') && !str_starts_with($path, '/setup')) {
            return;
        }

        // Le pare-feu a déjà répondu (401 du point d'entrée, 403 d'un voter) : on n'écrase rien.
        if (null !== $event->getResponse()) {
            return;
        }

        $exception = $event->getThrowable();

        $response = match (true) {
            $exception instanceof InvalidPayloadException => $this->json($exception->getMessage(), Response::HTTP_BAD_REQUEST),
            $exception instanceof ForbiddenException, $exception instanceof AccessDeniedException => $this->json($exception->getMessage(), Response::HTTP_FORBIDDEN),
            $exception instanceof NotFoundException => $this->json($exception->getMessage(), Response::HTTP_NOT_FOUND),
            $exception instanceof ConflictException => $this->json($exception->getMessage(), Response::HTTP_CONFLICT, $exception->getPayload()),
            $exception instanceof GoneException => $this->json($exception->getMessage(), Response::HTTP_GONE),
            $exception instanceof TooManyRequestsException => $this->tooManyRequests($exception),
            $exception instanceof HttpExceptionInterface => $this->json($exception->getMessage(), $exception->getStatusCode()),
            default => null,
        };

        if (null !== $response) {
            $event->setResponse($response);
        }
    }

    private function tooManyRequests(TooManyRequestsException $exception): JsonResponse
    {
        $response = $this->json($exception->getMessage(), Response::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string) $exception->getRetryAfterSeconds());

        return $response;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function json(string $message, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => $message] + $extra, $status);
    }
}
