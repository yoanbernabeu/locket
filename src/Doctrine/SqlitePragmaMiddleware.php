<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Applique les PRAGMA SQLite à chaque ouverture de connexion.
 *
 * POURQUOI un middleware et pas une option de configuration : les PRAGMA ne sont pas des
 * attributs PDO, ce sont des instructions SQL. Aucune clé de `doctrine.dbal.options` ne peut
 * les émettre — c'est précisément l'erreur que corrige ce fichier : la configuration
 * annonçait le mode WAL sans qu'aucune ligne de code ne l'active, et la base tournait en
 * `journal_mode = delete`.
 *
 * - `journal_mode = WAL` : sans lui, toute écriture verrouille la base entière. En mode worker
 *   FrankenPHP, plusieurs requêtes sont traitées en parallèle et se heurtent immédiatement à
 *   « database is locked ». Le réglage est persistant dans l'en-tête du fichier, mais il est
 *   réappliqué à chaque connexion : c'est le seul moyen de garantir qu'une base restaurée
 *   depuis une sauvegarde ou recréée à la main en bénéficie aussi.
 * - `synchronous = NORMAL` : en WAL, ce niveau ne risque une perte que sur coupure système
 *   brutale, jamais sur un simple plantage de processus. Le mode FULL coûte un fsync par
 *   transaction, pour protéger sept jours de messages.
 * - `busy_timeout` : fait patienter une transaction plutôt que d'échouer sur-le-champ.
 *
 * `foreign_keys` n'est délibérément PAS activé ici : SQLite ignore les clés étrangères par
 * défaut, et les activer changerait le comportement des suppressions bien au-delà du sujet de
 * ce fichier. C'est un point à traiter pour lui-même, avec les tests qui vont avec.
 */
#[AsMiddleware]
final class SqlitePragmaMiddleware implements Middleware
{
    /** @var list<string> */
    private const array PRAGMAS = [
        'PRAGMA journal_mode = WAL',
        'PRAGMA synchronous = NORMAL',
        'PRAGMA busy_timeout = 5000',
    ];

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, self::PRAGMAS) extends AbstractDriverMiddleware {
            /**
             * @param list<string> $pragmas
             */
            public function __construct(Driver $wrappedDriver, private readonly array $pragmas)
            {
                parent::__construct($wrappedDriver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                $connection = parent::connect($params);

                if (!self::isSqlite($params)) {
                    return $connection;
                }

                // Une base en mémoire n'a pas de journal : WAL y est refusé, sans conséquence.
                foreach ($this->pragmas as $pragma) {
                    $connection->exec($pragma);
                }

                return $connection;
            }

            /**
             * @param array<string, mixed> $params
             */
            private static function isSqlite(array $params): bool
            {
                $driver = $params['driver'] ?? '';

                return \is_string($driver) && str_contains($driver, 'sqlite');
            }
        };
    }
}
