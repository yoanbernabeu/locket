<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\Compiler\DisableAuxiliarySchemaListenersPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** Valeur livrée par le recipe symfony/mercure-bundle, présente dans `.env`. */
    private const string EXAMPLE_MERCURE_SECRET = '!ChangeThisMercureHubJWTSecretKey!';

    /** Le JWT est signé en HMAC-SHA256 : en dessous de la taille du bloc, la clé s'affaiblit. */
    private const int MIN_MERCURE_SECRET_BYTES = 32;

    /**
     * Refus de démarrage en production sur un secret Mercure inutilisable.
     *
     * POURQUOI ici et pas dans l'entrypoint : celui-ci génère bien un secret, mais rien ne
     * garantit qu'il soit le chemin emprunté — image lancée à la main, orchestrateur qui
     * fournit ses propres variables, `docker run` sans volume. Or ce secret signe à lui seul
     * les jetons d'abonnement du hub : connu, il donne accès au flux temps réel de toutes les
     * conversations. Mieux vaut un conteneur qui refuse de démarrer qu'une instance ouverte.
     */
    public function boot(): void
    {
        if ('prod' === $this->getEnvironment()) {
            $this->assertMercureSecretIsUsable();
        }

        parent::boot();
    }

    protected function build(ContainerBuilder $container): void
    {
        // Après optimisation : les définitions visées sont posées par DoctrineBundle.
        $container->addCompilerPass(new DisableAuxiliarySchemaListenersPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    private function assertMercureSecretIsUsable(): void
    {
        $secret = $_SERVER['MERCURE_JWT_SECRET'] ?? $_ENV['MERCURE_JWT_SECRET'] ?? null;

        if (!\is_string($secret) || '' === $secret) {
            throw new \RuntimeException('MERCURE_JWT_SECRET doit être défini : sans lui, aucun jeton d\'abonnement ne peut être signé.');
        }

        if (self::EXAMPLE_MERCURE_SECRET === $secret) {
            throw new \RuntimeException('MERCURE_JWT_SECRET porte encore la valeur d\'exemple. Générez un secret propre à cette instance.');
        }

        if (\strlen($secret) < self::MIN_MERCURE_SECRET_BYTES) {
            throw new \RuntimeException(\sprintf('MERCURE_JWT_SECRET doit faire au moins %d octets.', self::MIN_MERCURE_SECRET_BYTES));
        }
    }
}
