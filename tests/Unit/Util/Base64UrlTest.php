<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Exception\InvalidPayloadException;
use App\Util\Base64Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class Base64UrlTest extends TestCase
{
    #[TestDox('l\'encodage ne produit ni remplissage ni caractère non sûr pour une URL')]
    public function testEncodeProducesUrlSafeUnpaddedOutput(): void
    {
        for ($length = 1; $length <= 64; ++$length) {
            $encoded = Base64Url::encode(random_bytes($length));

            self::assertStringNotContainsString('=', $encoded);
            self::assertStringNotContainsString('+', $encoded);
            self::assertStringNotContainsString('/', $encoded);
        }

        self::assertSame('', Base64Url::encode(''));
    }

    #[TestDox('l\'aller-retour encodage/décodage restitue les octets à l\'identique')]
    public function testRoundTrip(): void
    {
        foreach ([1, 2, 3, 16, 32, 50, 130, 1024] as $length) {
            $raw = random_bytes($length);

            self::assertSame($raw, Base64Url::decode(Base64Url::encode($raw)));
        }

        self::assertSame('', Base64Url::decode(Base64Url::encode('')));
    }

    #[TestDox('les octets nuls et les séquences non UTF-8 survivent à l\'aller-retour')]
    public function testBinarySafety(): void
    {
        $raw = "\x00\xff\x00\xfe\x80\x01";

        self::assertSame($raw, Base64Url::decode(Base64Url::encode($raw)));
    }

    #[DataProvider('invalidInputs')]
    #[TestDox('une entrée mal formée est rejetée')]
    public function testDecodeRejectsInvalidInput(string $input): void
    {
        $this->expectException(InvalidPayloadException::class);

        Base64Url::decode($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'remplissage interdit' => ['QQ=='];
        yield 'alphabet base64 classique' => ['a+b/c'];
        yield 'espace' => ['ab cd'];
        yield 'caractère accentué' => ['abcé'];
    }

    #[TestDox('le décodage à longueur fixe refuse une taille inattendue')]
    public function testDecodeExactlyEnforcesLength(): void
    {
        $key = random_bytes(32);

        self::assertSame($key, Base64Url::decodeExactly(Base64Url::encode($key), 32, 'ikPub'));

        $this->expectException(InvalidPayloadException::class);
        Base64Url::decodeExactly(Base64Url::encode(random_bytes(31)), 32, 'ikPub');
    }
}
