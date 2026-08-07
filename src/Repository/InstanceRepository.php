<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Instance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Instance>
 */
class InstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Instance::class);
    }

    public function get(): ?Instance
    {
        return $this->find(Instance::SINGLETON_ID);
    }

    public function isInstalled(): bool
    {
        return $this->get()?->isInstalled() ?? false;
    }
}
