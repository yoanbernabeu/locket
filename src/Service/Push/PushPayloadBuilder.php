<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Util\Base64Url;

/**
 * Construit la charge utile décrite par PROTOCOL.md §8.
 *
 * BUDGET DE TAILLE : le chiffrement RFC 8291 impose un mur dur à 4096 octets, soit 3993
 * octets de clair. On s'arrête à 3900 pour garder une marge sur la variabilité du remplissage.
 * Au-delà, `lk.d` est OMIS et le service worker se contente d'afficher « Nouveau message »
 * avant de déclencher une synchronisation : sans ce chemin, le premier message long casserait
 * silencieusement la notification — et iOS révoque un abonnement qui n'affiche rien.
 */
final class PushPayloadBuilder
{
    public const int MAX_PAYLOAD_BYTES = 3900;

    private const string DEFAULT_TITLE = 'Locket';
    private const string DEFAULT_BODY = 'Nouveau message';

    /**
     * @param string $envelope enveloppe LK1M brute (octets), incluse si le budget le permet
     */
    public function build(
        string $conversationId,
        int $keyEpoch,
        string $envelope,
        string $navigateUrl,
        string $title = self::DEFAULT_TITLE,
    ): PushPayload {
        $withEnvelope = $this->encode($conversationId, $keyEpoch, Base64Url::encode($envelope), $navigateUrl, $title);

        if (\strlen($withEnvelope) <= self::MAX_PAYLOAD_BYTES) {
            return new PushPayload($withEnvelope, true);
        }

        return new PushPayload(
            $this->encode($conversationId, $keyEpoch, null, $navigateUrl, $title),
            false,
        );
    }

    private function encode(
        string $conversationId,
        int $keyEpoch,
        ?string $encodedEnvelope,
        string $navigateUrl,
        string $title,
    ): string {
        $lk = ['c' => $conversationId, 'e' => $keyEpoch];

        if (null !== $encodedEnvelope) {
            $lk['d'] = $encodedEnvelope;
        }

        return json_encode([
            // Déclare le format « Declarative Web Push » ; l'objet `notification` sert de
            // repli automatique sur WebKit si le service worker échoue.
            'web_push' => 8030,
            'notification' => [
                'title' => $title,
                'body' => self::DEFAULT_BODY,
                'navigate' => $navigateUrl,
            ],
            'lk' => $lk,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
