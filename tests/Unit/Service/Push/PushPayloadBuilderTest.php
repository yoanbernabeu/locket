<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Push;

use App\Service\Push\PushPayloadBuilder;
use App\Tests\Support\EnvelopeFactory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PushPayloadBuilderTest extends TestCase
{
    private PushPayloadBuilder $builder;
    private string $conversationId;

    protected function setUp(): void
    {
        $this->builder = new PushPayloadBuilder();
        $this->conversationId = Uuid::v7()->toRfc4122();
    }

    #[TestDox('le payload respecte le format de PROTOCOL.md §8')]
    public function testPayloadShape(): void
    {
        $payload = $this->builder->build($this->conversationId, 7, random_bytes(60), 'https://locket.test/c/abc');
        $decoded = json_decode($payload->json, true, 8, \JSON_THROW_ON_ERROR);

        self::assertSame(8030, $decoded['web_push']);
        self::assertSame('Locket', $decoded['notification']['title']);
        self::assertSame('Nouveau message', $decoded['notification']['body']);
        self::assertSame('https://locket.test/c/abc', $decoded['notification']['navigate']);
        self::assertSame($this->conversationId, $decoded['lk']['c']);
        self::assertSame(7, $decoded['lk']['e']);
        self::assertArrayHasKey('d', $decoded['lk']);
    }

    #[TestDox('une enveloppe courte est transportée telle quelle dans lk.d')]
    public function testShortEnvelopeIsInlined(): void
    {
        $envelope = EnvelopeFactory::envelope(Uuid::v7(), bodyBytes: 100);
        $payload = $this->builder->build($this->conversationId, 1, $envelope, 'https://locket.test/c/abc');

        self::assertTrue($payload->includesEnvelope);
        self::assertLessThanOrEqual(PushPayloadBuilder::MAX_PAYLOAD_BYTES, $payload->size());
    }

    #[TestDox('le payload push omet l\'enveloppe quand elle dépasse le budget')]
    public function testEnvelopeIsOmittedWhenBudgetIsExceeded(): void
    {
        $envelope = EnvelopeFactory::envelope(Uuid::v7(), bodyBytes: 8000);
        $payload = $this->builder->build($this->conversationId, 3, $envelope, 'https://locket.test/c/abc');

        self::assertFalse($payload->includesEnvelope);
        self::assertLessThanOrEqual(PushPayloadBuilder::MAX_PAYLOAD_BYTES, $payload->size());

        $decoded = json_decode($payload->json, true, 8, \JSON_THROW_ON_ERROR);

        // Le repli reste exploitable : le worker sait quelle conversation synchroniser.
        self::assertArrayNotHasKey('d', $decoded['lk']);
        self::assertSame($this->conversationId, $decoded['lk']['c']);
        self::assertSame(3, $decoded['lk']['e']);
        self::assertSame(8030, $decoded['web_push']);
    }

    #[TestDox('le budget est respecté sur toute la plage de tailles d\'enveloppe')]
    public function testBudgetHoldsAcrossSizes(): void
    {
        foreach ([50, 500, 1500, 2600, 2700, 2800, 3000, 4000, 65000] as $bodyBytes) {
            $payload = $this->builder->build(
                $this->conversationId,
                1,
                EnvelopeFactory::envelope(Uuid::v7(), bodyBytes: $bodyBytes),
                'https://locket.test/c/abc',
            );

            self::assertLessThanOrEqual(
                PushPayloadBuilder::MAX_PAYLOAD_BYTES,
                $payload->size(),
                \sprintf('Budget dépassé pour un corps de %d octets.', $bodyBytes),
            );
        }
    }
}
