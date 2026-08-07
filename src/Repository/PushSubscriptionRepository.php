<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use App\Entity\PushSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    public function findOneByDevice(Device $device): ?PushSubscription
    {
        return $this->findOneBy(['device' => $device]);
    }

    /**
     * @param list<Device> $devices
     *
     * @return list<PushSubscription>
     */
    public function findByDevices(array $devices): array
    {
        if ([] === $devices) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.device IN (:devices)')
            ->setParameter('devices', array_map(static fn (Device $device): string => $device->getId()->toBinary(), $devices), ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }
}
