<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Toutes les dates d'expiration de Locket se calculent ici, et nulle part ailleurs :
 * la rétention est une promesse produit, pas une constante éparpillée.
 */
final readonly class RetentionPolicy
{
    /**
     * Délai de grâce d'un média téléversé mais jamais rattaché à un message. Au-delà, plus
     * personne ne détient la clé qui permettrait de le lire : le garder n'a aucun intérêt.
     */
    public const int ORPHAN_MEDIA_GRACE_SECONDS = 3600;

    public function __construct(
        #[Autowire('%env(int:LOCKET_RETENTION_DAYS)%')]
        private int $retentionDays,
    ) {
        if ($this->retentionDays < 1) {
            throw new \InvalidArgumentException('LOCKET_RETENTION_DAYS doit valoir au moins 1.');
        }
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function expiresAt(\DateTimeImmutable $createdAt): \DateTimeImmutable
    {
        return $createdAt->add(new \DateInterval('P'.$this->retentionDays.'D'));
    }

    public function orphanMediaExpiresAt(\DateTimeImmutable $createdAt): \DateTimeImmutable
    {
        return $createdAt->modify('+'.self::ORPHAN_MEDIA_GRACE_SECONDS.' seconds');
    }

    /**
     * Seuil au-delà duquel une clé d'époque *non courante* devient purgeable.
     */
    public function keyCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub(new \DateInterval('P'.$this->retentionDays.'D'));
    }
}
