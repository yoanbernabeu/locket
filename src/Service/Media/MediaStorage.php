<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Attachment;
use App\Exception\GoneException;
use App\Exception\InvalidPayloadException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Stockage des blobs médias sur disque.
 *
 * RÈGLE ABSOLUE : le chemin est TOUJOURS dérivé du `mediaId`, jamais fourni par le client, et
 * le `mediaId` est validé contre un motif hexadécimal strict avant toute concaténation. C'est
 * la seule protection contre une traversée de répertoire, et elle doit rester la première
 * instruction de chaque méthode publique.
 *
 * Le partitionnement en deux niveaux de deux caractères évite de placer des dizaines de
 * milliers d'entrées dans un unique répertoire.
 */
final class MediaStorage
{
    private const string MEDIA_ID_PATTERN = '/\A[0-9a-f]{32}\z/';

    /** Taille de bloc de la copie en flux : assez grand pour être rapide, assez petit pour rester borné. */
    private const int CHUNK_BYTES = 262144;

    public function __construct(
        #[Autowire('%locket.media_dir%')]
        private readonly string $mediaDir,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function generateMediaId(): string
    {
        return bin2hex(random_bytes(Attachment::MEDIA_ID_BYTES));
    }

    /**
     * @throws InvalidPayloadException
     */
    public function assertValidMediaId(string $mediaId): void
    {
        if (1 !== preg_match(self::MEDIA_ID_PATTERN, $mediaId)) {
            throw new InvalidPayloadException('Identifiant de média invalide.');
        }
    }

    public function relativePath(string $mediaId): string
    {
        $this->assertValidMediaId($mediaId);

        return substr($mediaId, 0, 2).'/'.substr($mediaId, 2, 2).'/'.$mediaId;
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->mediaDir.'/'.$relativePath;
    }

    /**
     * @return string le chemin relatif à conserver dans l'entité Attachment
     */
    public function write(string $mediaId, string $contents): string
    {
        $relative = $this->relativePath($mediaId);
        $this->filesystem->dumpFile($this->absolutePath($relative), $contents);

        return $relative;
    }

    /**
     * Écriture en FLUX : le corps de la requête n'est jamais matérialisé en mémoire. Un envoi
     * de 25 Mio traité en mode worker immobiliserait sinon autant de mémoire résidente pour
     * toute la durée du processus, et plusieurs envois simultanés suffisent à faire tomber un
     * conteneur modeste.
     *
     * @param resource $source
     *
     * @return array{path: string, bytes: int}
     */
    public function writeStream(string $mediaId, $source, int $maxBytes): array
    {
        $relative = $this->relativePath($mediaId);
        $absolute = $this->absolutePath($relative);
        $this->filesystem->mkdir(\dirname($absolute));

        $target = fopen($absolute, 'wb');

        if (false === $target) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier de destination du média.');
        }

        $written = 0;

        try {
            while (!feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                $written += \strlen($chunk);

                // Le plafond est réappliqué pendant la copie : `Content-Length` est déclaré
                // par le client et ne prouve rien de ce qui arrive réellement.
                if ($written > $maxBytes) {
                    throw new GoneException(\sprintf('Média trop volumineux : maximum %d octets.', $maxBytes));
                }

                if (false === fwrite($target, $chunk)) {
                    throw new \RuntimeException('Écriture du média impossible.');
                }
            }
        } catch (\Throwable $exception) {
            fclose($target);
            $this->filesystem->remove($absolute);

            throw $exception;
        }

        fclose($target);

        if (0 === $written) {
            $this->filesystem->remove($absolute);

            throw new GoneException('Média vide.');
        }

        return ['path' => $relative, 'bytes' => $written];
    }

    public function read(string $mediaId): ?string
    {
        $absolute = $this->absolutePath($this->relativePath($mediaId));

        if (!is_file($absolute)) {
            return null;
        }

        $contents = file_get_contents($absolute);

        return false === $contents ? null : $contents;
    }

    public function exists(string $mediaId): bool
    {
        return is_file($this->absolutePath($this->relativePath($mediaId)));
    }

    /**
     * @return bool vrai si un fichier a effectivement été supprimé
     */
    public function delete(string $mediaId): bool
    {
        $absolute = $this->absolutePath($this->relativePath($mediaId));

        if (!is_file($absolute)) {
            return false;
        }

        $this->filesystem->remove($absolute);

        return true;
    }
}
