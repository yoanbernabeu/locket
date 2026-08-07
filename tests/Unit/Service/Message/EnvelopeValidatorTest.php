<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\Message;
use App\Exception\InvalidPayloadException;
use App\Service\Message\EnvelopeValidator;
use App\Tests\Support\EnvelopeFactory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EnvelopeValidatorTest extends TestCase
{
    private EnvelopeValidator $validator;
    private Uuid $conversationId;

    protected function setUp(): void
    {
        $this->validator = new EnvelopeValidator();
        $this->conversationId = Uuid::v7();
    }

    #[TestDox('une enveloppe bien formée expose son type et son époque')]
    public function testValidEnvelopeIsAccepted(): void
    {
        $header = $this->validator->validate(
            EnvelopeFactory::envelope($this->conversationId, epoch: 42, msgType: 0x02),
            $this->conversationId,
        );

        self::assertSame(0x01, $header->version);
        self::assertSame(0x02, $header->msgType);
        self::assertSame(42, $header->keyEpoch);
        self::assertTrue($this->conversationId->equals($header->conversationId));
    }

    #[TestDox('une enveloppe dont le convId ne correspond pas à l\'URL est rejetée')]
    public function testEnvelopeWithMismatchedConversationIdIsRejected(): void
    {
        $envelope = EnvelopeFactory::envelope(Uuid::v7());

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('identifiant de conversation');

        $this->validator->validate($envelope, $this->conversationId);
    }

    #[TestDox('une enveloppe plus courte que l\'en-tête est rejetée')]
    public function testTooShortEnvelopeIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        $this->validator->validate(random_bytes(Message::HEADER_BYTES - 1), $this->conversationId);
    }

    #[TestDox('une enveloppe de 50 octets exactement est acceptée')]
    public function testHeaderOnlyEnvelopeIsAccepted(): void
    {
        $envelope = substr(EnvelopeFactory::envelope($this->conversationId), 0, Message::HEADER_BYTES);

        self::assertSame(Message::HEADER_BYTES, \strlen($envelope));
        self::assertSame(1, $this->validator->validate($envelope, $this->conversationId)->keyEpoch);
    }

    #[TestDox('une enveloppe dépassant 64 Kio est rejetée')]
    public function testOversizedEnvelopeIsRejected(): void
    {
        $envelope = EnvelopeFactory::envelope($this->conversationId, bodyBytes: Message::MAX_ENVELOPE_BYTES);

        $this->expectException(InvalidPayloadException::class);

        $this->validator->validate($envelope, $this->conversationId);
    }

    #[TestDox('une version d\'enveloppe inconnue est rejetée')]
    public function testUnknownVersionIsRejected(): void
    {
        $envelope = EnvelopeFactory::envelope($this->conversationId, version: 0x02);

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('Version');

        $this->validator->validate($envelope, $this->conversationId);
    }

    #[TestDox('une époque proche de la borne uint32 est lue sans débordement')]
    public function testLargeEpochIsDecoded(): void
    {
        $envelope = EnvelopeFactory::envelope($this->conversationId, epoch: 4294967295);

        self::assertSame(4294967295, $this->validator->validate($envelope, $this->conversationId)->keyEpoch);
    }
}
