<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\DeviceContext;
use App\Service\Media\MediaService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/media')]
final class MediaController extends AbstractApiController
{
    public function __construct(
        private readonly MediaService $media,
        private readonly DeviceContext $deviceContext,
    ) {
    }

    #[Route('', name: 'api_media_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $this->deviceContext->requireActiveDevice();

        $attachment = $this->media->store(
            $this->deviceContext->requireUser(),
            // `true` demande le corps sous forme de ressource : il n'est jamais concaténé en
            // mémoire, ni ici ni dans la couche de stockage.
            $request->getContent(true),
            $this->declaredLength($request),
        );

        return new JsonResponse(['mediaId' => $attachment->getMediaId()], Response::HTTP_CREATED);
    }

    #[Route('/{mediaId}', name: 'api_media_download', methods: ['GET'])]
    public function download(string $mediaId): BinaryFileResponse
    {
        $this->deviceContext->requireActiveDevice();
        $path = $this->media->locate($mediaId, $this->deviceContext->requireUser());

        $response = new BinaryFileResponse($path);
        $response->setAutoLastModified();

        // Le blob est déjà chiffré côté client : le serveur ne connaît ni son type réel ni
        // son contenu. `application/octet-stream` est donc le seul type honnête, et le
        // `nosniff` évite qu'un navigateur devine mieux que nous.
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }

    private function declaredLength(Request $request): ?int
    {
        $header = $request->headers->get('Content-Length');

        return null === $header || 1 !== preg_match('/\A\d{1,19}\z/', $header) ? null : (int) $header;
    }
}
