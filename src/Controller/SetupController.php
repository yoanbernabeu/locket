<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Api\AbstractApiController;
use App\Dto\DeviceEnrollment;
use App\Service\Installation\InstallationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Assistant de premier lancement.
 *
 * Le GET rend le formulaire ; le POST est consommé en JSON par le JavaScript de
 * la page. Ce détour n'est pas un caprice d'architecture : la création du compte
 * administrateur doit générer les clés de son premier appareil, ce que seul le
 * navigateur peut faire. Un formulaire HTML classique n'aurait rien à envoyer.
 *
 * Une fois l'instance installée, les deux méthodes renvoient 404 : l'assistant
 * ne doit laisser aucune prise une fois sa raison d'être disparue.
 */
#[Route('/setup')]
final class SetupController extends AbstractApiController
{
    public function __construct(private readonly InstallationService $installation)
    {
    }

    #[Route('', name: 'setup_show', methods: ['GET'])]
    public function show(): Response
    {
        $this->assertNotInstalled();

        return $this->render('setup/install.html.twig');
    }

    #[Route('', name: 'setup_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $this->assertNotInstalled();
        $payload = $this->payload($request);

        $result = $this->installation->install(
            $this->optionalString($payload, 'instanceName'),
            $this->requiredString($payload, 'pseudo'),
            $this->optionalString($payload, 'displayName', '') ?? '',
            $this->optionalString($payload, 'email'),
            $this->requiredString($payload, 'pin'),
            DeviceEnrollment::fromPayload($payload['device'] ?? null),
        );

        return new JsonResponse([
            'instance' => ['name' => $result['instance']->getName()],
            'user' => [
                'id' => $result['user']->getId()->toRfc4122(),
                'pseudo' => $result['user']->getPseudo(),
                'isAdmin' => true,
            ],
            'device' => ['deviceId' => $result['device']->getDeviceId(), 'status' => $result['device']->getStatus()->value],
            'conversation' => ['id' => $result['conversation']->getId()->toRfc4122()],
        ], Response::HTTP_CREATED);
    }

    private function assertNotInstalled(): void
    {
        if ($this->installation->isInstalled()) {
            throw new NotFoundHttpException('Cette instance est déjà installée.');
        }
    }
}
