<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\MessageType;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class MessageTypeTest extends TestCase
{
    #[TestDox('une réaction ne déclenche jamais de notification')]
    public function testReactionsAreSilent(): void
    {
        self::assertFalse(MessageType::shouldNotify(MessageType::Reaction->value));
        self::assertFalse(MessageType::shouldNotify(MessageType::Control->value));
    }

    #[TestDox('un texte ou un média déclenche une notification')]
    public function testContentNotifies(): void
    {
        self::assertTrue(MessageType::shouldNotify(MessageType::Text->value));
        self::assertTrue(MessageType::shouldNotify(MessageType::Media->value));
    }

    #[TestDox('un type inconnu d\'un client plus récent notifie par défaut')]
    public function testUnknownTypeNotifies(): void
    {
        self::assertTrue(MessageType::shouldNotify(0x7f));
    }
}
