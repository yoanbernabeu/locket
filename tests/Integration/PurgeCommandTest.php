<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\AttachmentRepository;
use App\Service\Media\MediaService;
use App\Service\Media\MediaStorage;
use App\Tests\Support\DomainTestCase;
use App\Tests\Support\Streams;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeCommandTest extends DomainTestCase
{
    #[TestDox('C25 : la commande de purge supprime réellement les données expirées')]
    public function testPurgeCommandActuallyDeletesExpiredData(): void
    {
        $admin = $this->fixtures->install()['user'];

        $media = self::getContainer()->get(MediaService::class);
        \assert($media instanceof MediaService);
        $storage = self::getContainer()->get(MediaStorage::class);
        \assert($storage instanceof MediaStorage);
        $attachments = self::getContainer()->get(AttachmentRepository::class);
        \assert($attachments instanceof AttachmentRepository);

        $attachment = $media->store($admin, Streams::of(random_bytes(1024)));
        $mediaId = $attachment->getMediaId();
        $this->expire($mediaId);

        self::assertTrue($storage->exists($mediaId), 'Le fichier doit exister avant la purge.');

        $tester = $this->runPurge();

        // L'ancien test se contentait de vérifier la présence de trois libellés dans la
        // sortie : il passait sur une base vide, et serait resté vert même si la purge
        // n'avait rien supprimé du tout.
        self::assertNull($attachments->findOneByMediaId($mediaId));
        self::assertFalse($storage->exists($mediaId));
        self::assertMatchesRegularExpression('/Pièces jointes supprimées\s+1/u', $tester->getDisplay());
    }

    #[TestDox('C25 : la commande ne supprime rien qui ne soit expiré')]
    public function testPurgeCommandSparesLivingData(): void
    {
        $admin = $this->fixtures->install()['user'];

        $media = self::getContainer()->get(MediaService::class);
        \assert($media instanceof MediaService);
        $storage = self::getContainer()->get(MediaStorage::class);
        \assert($storage instanceof MediaStorage);

        // Déposé à l'instant : il lui reste une heure de délai de grâce.
        $attachment = $media->store($admin, Streams::of(random_bytes(512)));

        $tester = $this->runPurge();

        self::assertTrue($storage->exists($attachment->getMediaId()));
        self::assertMatchesRegularExpression('/Pièces jointes supprimées\s+0/u', $tester->getDisplay());
    }

    private function runPurge(): CommandTester
    {
        $application = new Application(self::$kernel ?? throw new \RuntimeException('Noyau absent.'));
        $tester = new CommandTester($application->find('locket:purge'));

        self::assertSame(0, $tester->execute([]));

        $this->entityManager->clear();

        return $tester;
    }

    /**
     * Antidate l'expiration d'un média : la commande utilise l'horloge réelle, on ne peut donc
     * pas se placer dans le futur — on rend la donnée expirée dans le présent.
     */
    private function expire(string $mediaId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE attachment SET expires_at = :date WHERE media_id = :mediaId',
            ['date' => '2000-01-01 00:00:00', 'mediaId' => $mediaId],
        );
        $this->entityManager->clear();
    }
}
