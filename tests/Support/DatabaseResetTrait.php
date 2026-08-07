<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Remise à zéro du schéma et du répertoire de médias entre deux tests.
 *
 * Les tables sont supprimées explicitement plutôt que via SchemaTool::dropSchema() : sur une
 * base neuve, ce dernier tenterait de supprimer des tables inexistantes.
 */
trait DatabaseResetTrait
{
    protected function resetDatabase(ContainerInterface $container): void
    {
        $entityManager = $container->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $connection = $entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();

        foreach ($schemaManager->listTableNames() as $table) {
            $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS "%s"', $table));
        }

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        (new SchemaTool($entityManager))->createSchema($metadata);
        $entityManager->clear();
    }

    protected function resetMediaDirectory(ContainerInterface $container): void
    {
        $mediaDir = $container->getParameter('locket.media_dir');
        \assert(\is_string($mediaDir));

        $filesystem = new Filesystem();

        if ($filesystem->exists($mediaDir)) {
            $filesystem->remove($mediaDir);
        }
    }
}
