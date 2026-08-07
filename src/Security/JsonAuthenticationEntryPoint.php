<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Point d'entrée d'authentification.
 *
 * L'API ne redirige jamais vers un formulaire : un client PWA attend un 401
 * exploitable, qu'il traduit lui-même en écran de connexion.
 *
 * Les écrans rendus par le serveur, eux, doivent rediriger. Sans cette
 * distinction, ouvrir /admin sans session affichait `{"error":"Authentification
 * requise."}` en JSON brut sur fond noir — un écran qu'un parent finira par
 * voir, et qui ne lui dit ni ce qui se passe ni quoi faire.
 */
final class JsonAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($this->attendDuJson($request)) {
            return new JsonResponse(
                ['error' => 'Authentification requise.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        // L'application se charge d'afficher l'écran de connexion : elle seule
        // sait si l'appareil possède déjà une identité locale.
        return new RedirectResponse('/');
    }

    private function attendDuJson(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/')
            || 'json' === $request->getPreferredFormat(null)
            || $request->isXmlHttpRequest();
    }
}
