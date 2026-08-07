<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Écouteur de remplacement, volontairement vide.
 * Voir App\DependencyInjection\Compiler\DisableAuxiliarySchemaListenersPass.
 */
final class NoopSchemaListener
{
    /**
     * Signature variadique : la classe se substitue à des écouteurs aux dépendances variées,
     * dont les arguments restent câblés dans le conteneur.
     */
    public function __construct(mixed ...$ignoredDependencies)
    {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
    }
}
