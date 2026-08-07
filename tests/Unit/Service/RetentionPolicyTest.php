<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\RetentionPolicy;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    #[TestDox('l\'expiration d\'un message vaut sa création plus la durée de rétention')]
    public function testMessageExpiry(): void
    {
        $policy = new RetentionPolicy(7);
        $createdAt = new \DateTimeImmutable('2026-03-01 08:30:00');

        self::assertSame('2026-03-08 08:30:00', $policy->expiresAt($createdAt)->format('Y-m-d H:i:s'));
    }

    #[TestDox('un média orphelin expire au bout d\'une heure')]
    public function testOrphanMediaExpiry(): void
    {
        $policy = new RetentionPolicy(7);
        $createdAt = new \DateTimeImmutable('2026-03-01 08:30:00');

        self::assertSame('2026-03-01 09:30:00', $policy->orphanMediaExpiresAt($createdAt)->format('Y-m-d H:i:s'));
    }

    #[TestDox('le seuil de purge des clés recule de la durée de rétention')]
    public function testKeyCutoff(): void
    {
        $policy = new RetentionPolicy(7);

        self::assertSame(
            '2026-02-22 08:30:00',
            $policy->keyCutoff(new \DateTimeImmutable('2026-03-01 08:30:00'))->format('Y-m-d H:i:s'),
        );
    }

    #[TestDox('la durée de rétention est configurable')]
    public function testCustomRetention(): void
    {
        $policy = new RetentionPolicy(30);

        self::assertSame(30, $policy->getRetentionDays());
        self::assertSame(
            '2026-03-31 00:00:00',
            $policy->expiresAt(new \DateTimeImmutable('2026-03-01 00:00:00'))->format('Y-m-d H:i:s'),
        );
    }

    #[TestDox('une rétention nulle ou négative est refusée à la construction')]
    public function testInvalidRetentionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RetentionPolicy(0);
    }
}
