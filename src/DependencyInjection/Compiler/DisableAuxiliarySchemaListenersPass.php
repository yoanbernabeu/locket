<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use App\Doctrine\NoopSchemaListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Neutralise les écouteurs `postGenerateSchema` du pont Doctrine de Symfony.
 *
 * POURQUOI : symfony/doctrine-bridge 8.1 appelle `GenerateSchemaEventArgs::setSchema()`
 * dès que la méthode existe ; or doctrine/orm 3.6.8 la déclare mais la fait échouer tant que
 * doctrine/dbal n'atteint pas 4.5 (non publié en version stable à ce jour). Résultat : toute
 * génération de schéma — `doctrine:schema:create`, `doctrine:migrations:diff`, et surtout la
 * création du schéma de test — lève une BadMethodCallException, même sans aucune table
 * auxiliaire à ajouter.
 *
 * Ces écouteurs n'ajoutent au schéma que des tables dont Locket ne se sert pas : cache DBAL
 * (Locket utilise le cache fichier), sessions PDO (sessions natives), jetons « se souvenir de
 * moi » (non activés), verrous (LOCK_DSN=flock) et transports Messenger — ces derniers étant
 * créés explicitement par `bin/console messenger:setup-transports`, appelé par
 * `locket:install`. Les retirer est donc sans effet fonctionnel.
 *
 * La détection se fait sur l'espace de noms de la classe et non sur des identifiants de
 * services codés en dur : ceux-ci diffèrent d'un fichier de configuration à l'autre dans
 * DoctrineBundle, et un oubli ne se manifesterait qu'au premier appel de génération de
 * schéma. La comparaison reste textuelle, sans `is_a()` : à la compilation, déclencher le
 * chargement de toutes les classes de services ferait échouer celles dont une dépendance
 * facultative est absente.
 *
 * À SUPPRIMER dès que doctrine/dbal ^4.5 est disponible en version stable.
 */
final class DisableAuxiliarySchemaListenersPass implements CompilerPassInterface
{
    private const string LISTENER_NAMESPACE = 'Symfony\\Bridge\\Doctrine\\SchemaListener\\';

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();

            if (null === $class || !str_starts_with($class, self::LISTENER_NAMESPACE)) {
                continue;
            }

            // La définition est conservée, arguments compris : le gestionnaire d'événements
            // Doctrine la référence déjà, et une passe de DoctrineBundle réécrit ensuite
            // l'argument 0 de l'écouteur de cache. Seule l'implémentation est remplacée par
            // une version inerte, qui absorbe n'importe quels arguments.
            $definition->setClass(NoopSchemaListener::class);
        }
    }
}
