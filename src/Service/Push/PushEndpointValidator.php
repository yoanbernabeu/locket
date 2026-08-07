<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Exception\InvalidPayloadException;

/**
 * Contrôle de l'URL d'abonnement Web Push.
 *
 * POURQUOI c'est une barrière de sécurité et non une validation de confort : cette URL est
 * fournie par le client et le serveur y émettra ensuite des requêtes HTTP authentifiées, en
 * boucle, depuis l'intérieur du réseau. Sans contrôle, n'importe quel membre transforme
 * l'instance en relais vers son réseau local, la passerelle de métadonnées de son hébergeur ou
 * l'API d'administration de Caddy — c'est une SSRF authentifiée.
 *
 * On ne résout PAS le nom d'hôte ici : une résolution au moment de la validation ne prouve
 * rien sur celle qui aura lieu à l'envoi (réattachement DNS). Le filtrage porte donc sur ce
 * qui est vérifiable de manière stable — schéma, port, littéraux d'adresse et noms réservés.
 */
final class PushEndpointValidator
{
    /** Aligné sur la colonne `push_subscription.endpoint`. */
    public const int MAX_LENGTH = 512;

    private const int ALLOWED_PORT = 443;

    /** Suffixes que les résolveurs locaux détournent vers le réseau interne. */
    private const array RESERVED_SUFFIXES = ['.local', '.localhost', '.internal', '.intranet', '.lan', '.home.arpa'];

    private const array RESERVED_HOSTS = ['localhost'];

    public function validate(string $endpoint): string
    {
        if ('' === $endpoint || \strlen($endpoint) > self::MAX_LENGTH) {
            throw new InvalidPayloadException(\sprintf('L\'adresse d\'abonnement doit faire entre 1 et %d octets.', self::MAX_LENGTH));
        }

        // Un octet de contrôle permettrait une injection d'en-tête dans la requête sortante.
        if (1 === preg_match('/[\x00-\x20\x7f]/', $endpoint)) {
            throw new InvalidPayloadException('L\'adresse d\'abonnement contient un caractère interdit.');
        }

        $parts = parse_url($endpoint);

        if (!\is_array($parts)) {
            throw new InvalidPayloadException('L\'adresse d\'abonnement n\'est pas une URL valide.');
        }

        if ('https' !== ($parts['scheme'] ?? null)) {
            throw new InvalidPayloadException('L\'adresse d\'abonnement doit utiliser le schéma https.');
        }

        // Des identifiants dans l'URL servent surtout à masquer l'hôte réel à un lecteur humain.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidPayloadException('L\'adresse d\'abonnement ne peut pas porter d\'identifiants.');
        }

        if (self::ALLOWED_PORT !== ($parts['port'] ?? self::ALLOWED_PORT)) {
            throw new InvalidPayloadException('L\'adresse d\'abonnement doit utiliser le port 443.');
        }

        $this->assertHostIsPublic(trim((string) ($parts['host'] ?? ''), '[]'));

        return $endpoint;
    }

    private function assertHostIsPublic(string $host): void
    {
        if ('' === $host) {
            throw new InvalidPayloadException('L\'adresse d\'abonnement doit désigner un hôte.');
        }

        $host = mb_strtolower($host);

        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            // NO_PRIV_RANGE couvre RFC1918 et fc00::/7 ; NO_RES_RANGE couvre la boucle locale,
            // 0.0.0.0/8, le lien-local 169.254/16 et fe80::/10.
            if (false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidPayloadException('L\'adresse d\'abonnement vise une adresse IP non routable sur Internet.');
            }

            return;
        }

        if (\in_array($host, self::RESERVED_HOSTS, true)) {
            throw new InvalidPayloadException('L\'adresse d\'abonnement vise un hôte réservé.');
        }

        foreach (self::RESERVED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new InvalidPayloadException('L\'adresse d\'abonnement vise un hôte réservé.');
            }
        }

        // Au moins un point : un nom d'une seule étiquette ne peut désigner qu'une machine du
        // réseau interne, jamais un service de push public.
        if (1 !== preg_match('/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/', $host)) {
            throw new InvalidPayloadException('L\'adresse d\'abonnement doit désigner un nom de domaine public.');
        }
    }
}
