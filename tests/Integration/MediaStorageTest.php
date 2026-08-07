<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Exception\ForbiddenException;
use App\Exception\GoneException;
use App\Exception\InvalidPayloadException;
use App\Service\Media\MediaService;
use App\Service\Media\MediaStorage;
use App\Tests\Support\DomainTestCase;
use App\Tests\Support\Streams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

final class MediaStorageTest extends DomainTestCase
{
    private MediaStorage $storage;
    private MediaService $media;

    protected function setUp(): void
    {
        parent::setUp();

        $storage = self::getContainer()->get(MediaStorage::class);
        \assert($storage instanceof MediaStorage);
        $this->storage = $storage;

        $media = self::getContainer()->get(MediaService::class);
        \assert($media instanceof MediaService);
        $this->media = $media;
    }

    #[TestDox('le chemin d\'un média est partitionné à partir de son identifiant')]
    public function testPathIsShardedFromMediaId(): void
    {
        $mediaId = '0123456789abcdef0123456789abcdef';

        self::assertSame('01/23/0123456789abcdef0123456789abcdef', $this->storage->relativePath($mediaId));
    }

    #[DataProvider('hostileMediaIds')]
    #[TestDox('un identifiant de média hostile est rejeté avant toute concaténation de chemin')]
    public function testHostileMediaIdIsRejected(string $mediaId): void
    {
        $this->expectException(InvalidPayloadException::class);

        $this->storage->relativePath($mediaId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileMediaIds(): iterable
    {
        yield 'traversée de répertoire' => ['../../../../etc/passwd'];
        yield 'séparateur de chemin' => ['0123456789abcdef/0123456789abcde'];
        yield 'octet nul' => ["0123456789abcdef0123456789abcde\0"];
        yield 'majuscules' => ['0123456789ABCDEF0123456789ABCDEF'];
        yield 'trop court' => ['0123'];
        yield 'vide' => [''];
    }

    #[TestDox('un média déposé se relit à l\'octet près')]
    public function testRoundTrip(): void
    {
        $owner = $this->fixtures->install()['user'];
        $contents = random_bytes(5000);

        $attachment = $this->media->store($owner, Streams::of($contents));

        self::assertSame($contents, $this->storage->read($attachment->getMediaId()));
        self::assertSame(5000, $attachment->getBytes());
    }

    #[TestDox('un média dépassant 25 Mio est refusé')]
    public function testOversizedMediaIsRejected(): void
    {
        $owner = $this->fixtures->install()['user'];

        $this->expectException(GoneException::class);

        $this->media->store($owner, Streams::of(str_repeat('a', 25 * 1024 * 1024 + 1)));
    }

    #[TestDox('un média non rattaché n\'est lisible que par son déposant')]
    public function testUnlinkedMediaIsPrivateToItsOwner(): void
    {
        $owner = $this->fixtures->install()['user'];
        $other = $this->fixtures->addMember('mamie')['user'];

        $attachment = $this->media->store($owner, Streams::of(random_bytes(128)));

        self::assertSame(
            $attachment->getMediaId(),
            $this->media->authorize($attachment->getMediaId(), $owner)->getMediaId(),
        );

        $this->expectException(ForbiddenException::class);
        $this->media->authorize($attachment->getMediaId(), $other);
    }
}
