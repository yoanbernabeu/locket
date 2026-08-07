<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests d'intégration : noyau démarré, base SQLite recréée, aucun HTTP.
 */
abstract class DomainTestCase extends KernelTestCase
{
    use DatabaseResetTrait;

    protected EntityManagerInterface $entityManager;
    protected Fixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->resetDatabase($container);
        $this->resetMediaDirectory($container);

        $entityManager = $container->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $this->fixtures = new Fixtures($container);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }
}
