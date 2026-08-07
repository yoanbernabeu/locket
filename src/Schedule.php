<?php

declare(strict_types=1);

namespace App;

use App\Message\PurgeExpiredData;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            // Horaire : la rétention se compte en jours, une purge horaire suffit largement
            // et lisse la charge d'entrées/sorties au lieu de tout supprimer d'un coup.
            ->add(RecurringMessage::every('1 hour', new PurgeExpiredData()));
    }
}
