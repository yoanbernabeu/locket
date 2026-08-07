<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Push;

use App\Exception\InvalidPayloadException;
use App\Service\Push\PushEndpointValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class PushEndpointValidatorTest extends TestCase
{
    private PushEndpointValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PushEndpointValidator();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileEndpoints(): iterable
    {
        yield 'http en clair' => ['http://push.example.test/abonnement'];
        yield 'boucle locale IPv4' => ['https://127.0.0.1/interne'];
        yield 'boucle locale IPv6' => ['https://[::1]/interne'];
        yield 'nom localhost' => ['https://localhost/interne'];
        yield 'RFC1918 10/8' => ['https://10.0.0.5/interne'];
        yield 'RFC1918 172.16/12' => ['https://172.16.3.4/interne'];
        yield 'RFC1918 192.168/16' => ['https://192.168.0.1/interne'];
        yield 'IPv6 unique-local' => ['https://[fd00::1]/interne'];
        yield 'lien-local, passerelle de métadonnées' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'adresse nulle' => ['https://0.0.0.0/interne'];
        yield 'port non standard' => ['https://push.example.test:8080/abonnement'];
        yield 'identifiants dans l\'URL' => ['https://user:pass@push.example.test/abonnement'];
        yield 'nom d\'une seule étiquette' => ['https://nas/partage'];
        yield 'suffixe .local' => ['https://imprimante.local/etat'];
        yield 'suffixe .internal' => ['https://api.internal/secret'];
        yield 'retour chariot injecté' => ["https://push.example.test/a\r\nX-Injecte: 1"];
        yield 'schéma absent' => ['push.example.test/abonnement'];
        yield 'hôte absent' => ['https:///abonnement'];
        yield 'chaîne vide' => [''];
    }

    #[DataProvider('hostileEndpoints')]
    #[TestDox('C14 : une adresse d\'abonnement hostile est refusée')]
    public function testHostileEndpointIsRejected(string $endpoint): void
    {
        $this->expectException(InvalidPayloadException::class);

        $this->validator->validate($endpoint);
    }

    #[TestDox('C14 : une adresse trop longue est refusée avant tout traitement')]
    public function testOverlongEndpointIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        $this->validator->validate('https://push.example.test/'.str_repeat('a', PushEndpointValidator::MAX_LENGTH));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legitimateEndpoints(): iterable
    {
        yield 'Mozilla' => ['https://updates.push.services.mozilla.com/wpush/v2/gAAAAABk'];
        yield 'Google' => ['https://fcm.googleapis.com/fcm/send/abcdef:APA91b'];
        yield 'Apple' => ['https://web.push.apple.com/QAAAA'];
        yield 'port 443 explicite' => ['https://push.example.test:443/abonnement'];
        yield 'adresse IP publique' => ['https://93.184.216.34/abonnement'];
    }

    #[DataProvider('legitimateEndpoints')]
    #[TestDox('C14 : une adresse de service de push public reste acceptée')]
    public function testLegitimateEndpointIsAccepted(string $endpoint): void
    {
        self::assertSame($endpoint, $this->validator->validate($endpoint));
    }
}
