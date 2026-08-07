<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\InstanceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Sert la coquille de l'application de messagerie.
 *
 * Toutes les routes rendent le MÊME gabarit vide : le serveur ne peut afficher
 * aucun contenu, puisqu'il ne détient aucune clé de déchiffrement. C'est le
 * JavaScript qui, après déchiffrement local, décide quoi montrer selon l'URL.
 */
final class AppController extends AbstractController
{
    public function __construct(private readonly InstanceRepository $instances)
    {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Route('/reglages', name: 'app_settings', methods: ['GET'])]
    #[Route('/c/{id}', name: 'app_conversation', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function shell(): Response
    {
        // Tant que l'instance n'existe pas, il n'y a rien à afficher : on envoie
        // l'administrateur vers l'assistant d'installation.
        if (!$this->instances->isInstalled()) {
            return $this->redirectToRoute('setup_show');
        }

        return $this->render('app/index.html.twig', [
            'instance_name' => $this->instanceName(),
        ]);
    }

    /**
     * Page d'acceptation d'une invitation.
     *
     * Le secret vit dans le fragment de l'URL, que le navigateur n'envoie jamais
     * au serveur : cette action ne reçoit donc rien d'exploitable, et c'est
     * exactement l'effet recherché.
     */
    #[Route('/j', name: 'app_join', methods: ['GET'])]
    public function join(): Response
    {
        return $this->render('app/index.html.twig', [
            'instance_name' => $this->instanceName(),
        ]);
    }

    #[Route('/offline', name: 'app_offline', methods: ['GET'])]
    public function offline(): Response
    {
        return $this->render('app/offline.html.twig', [
            'instance_name' => $this->instanceName(),
        ]);
    }

    /**
     * Sonde de disponibilité utilisée par Docker.
     * Volontairement muette : elle ne doit rien révéler d'une instance familiale.
     */
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): Response
    {
        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    private function instanceName(): string
    {
        return $this->instances->get()?->getName() ?? 'Locket';
    }
}
