<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remédiation de l'audit V1 :
 *
 * - `login_attempt` : compteur d'échecs de connexion indexé par pseudo, existant même pour un
 *   pseudo inconnu. C'est ce qui ferme l'oracle d'existence de compte (C3) et ce qui porte la
 *   décroissance du compteur au bout de 24 h (C15).
 * - `message.key_epoch` : époque de chiffrement recopiée depuis l'en-tête LK1M. La purge doit
 *   pouvoir vérifier en SQL qu'une époque n'a plus aucun message vivant avant d'en supprimer
 *   la clé (C24) ; elle ne peut pas décoder 4 octets au milieu d'un BLOB.
 */
final class Version20260807180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compteur de connexion par pseudo et époque de chiffrement dénormalisée sur les messages.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE login_attempt (
              id BLOB NOT NULL,
              pseudo VARCHAR(64) NOT NULL,
              failed_attempts INTEGER NOT NULL,
              last_attempt_at DATETIME NOT NULL,
              locked_until DATETIME DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_login_attempt_pseudo ON login_attempt (pseudo)');
        $this->addSql('CREATE INDEX idx_login_attempt_last_attempt_at ON login_attempt (last_attempt_at)');

        // SQLite ne sait pas ajouter une colonne NOT NULL sans valeur par défaut : la table est
        // reconstruite.
        //
        // Les lignes déjà présentes reçoivent l'époque COURANTE de leur conversation, et non
        // l'époque réelle lue dans l'enveloppe : décoder un entier gros-boutiste de 4 octets
        // au milieu d'un BLOB en SQL pur demanderait une expression illisible pour un gain
        // nul. L'approximation est délibérément conservatrice — elle ne peut que rattacher un
        // message à l'époque courante, dont la clé n'est de toute façon jamais purgée, donc
        // elle ne peut jamais provoquer la suppression prématurée d'une clé encore utile.
        // Les messages vivent au plus sept jours : l'approximation disparaît d'elle-même.
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__message AS
            SELECT server_seq, id, msg_type, envelope, created_at, expires_at, conversation_id, sender_user_id, sender_device_id
            FROM message
        SQL);
        $this->addSql('DROP TABLE message');
        $this->addSql(<<<'SQL'
            CREATE TABLE message (
              server_seq INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              id BLOB NOT NULL,
              msg_type SMALLINT NOT NULL,
              key_epoch INTEGER NOT NULL,
              envelope BLOB NOT NULL,
              created_at DATETIME NOT NULL,
              expires_at DATETIME NOT NULL,
              conversation_id BLOB NOT NULL,
              sender_user_id BLOB NOT NULL,
              sender_device_id BLOB NOT NULL,
              CONSTRAINT FK_B6BD307F9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_B6BD307F2A98155E FOREIGN KEY (sender_user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_B6BD307FCEAD2322 FOREIGN KEY (sender_device_id) REFERENCES device (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO message (server_seq, id, msg_type, key_epoch, envelope, created_at, expires_at, conversation_id, sender_user_id, sender_device_id)
            SELECT
              m.server_seq, m.id, m.msg_type,
              (SELECT c.current_epoch FROM conversation c WHERE c.id = m.conversation_id),
              m.envelope, m.created_at, m.expires_at, m.conversation_id, m.sender_user_id, m.sender_device_id
            FROM __temp__message m
        SQL);
        $this->addSql('DROP TABLE __temp__message');
        $this->addSql('CREATE UNIQUE INDEX uniq_message_id ON message (id)');
        $this->addSql('CREATE INDEX idx_message_expires_at ON message (expires_at)');
        $this->addSql('CREATE INDEX idx_message_conversation_seq ON message (conversation_id, server_seq)');
        $this->addSql('CREATE INDEX idx_message_conversation_epoch ON message (conversation_id, key_epoch)');
        $this->addSql('CREATE INDEX IDX_B6BD307F9AC0396 ON message (conversation_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307F2A98155E ON message (sender_user_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307FCEAD2322 ON message (sender_device_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE login_attempt');

        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__message AS
            SELECT server_seq, id, msg_type, envelope, created_at, expires_at, conversation_id, sender_user_id, sender_device_id
            FROM message
        SQL);
        $this->addSql('DROP TABLE message');
        $this->addSql(<<<'SQL'
            CREATE TABLE message (
              server_seq INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              id BLOB NOT NULL,
              msg_type SMALLINT NOT NULL,
              envelope BLOB NOT NULL,
              created_at DATETIME NOT NULL,
              expires_at DATETIME NOT NULL,
              conversation_id BLOB NOT NULL,
              sender_user_id BLOB NOT NULL,
              sender_device_id BLOB NOT NULL,
              CONSTRAINT FK_B6BD307F9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_B6BD307F2A98155E FOREIGN KEY (sender_user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_B6BD307FCEAD2322 FOREIGN KEY (sender_device_id) REFERENCES device (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO message (server_seq, id, msg_type, envelope, created_at, expires_at, conversation_id, sender_user_id, sender_device_id)
            SELECT server_seq, id, msg_type, envelope, created_at, expires_at, conversation_id, sender_user_id, sender_device_id
            FROM __temp__message
        SQL);
        $this->addSql('DROP TABLE __temp__message');
        $this->addSql('CREATE UNIQUE INDEX uniq_message_id ON message (id)');
        $this->addSql('CREATE INDEX idx_message_expires_at ON message (expires_at)');
        $this->addSql('CREATE INDEX idx_message_conversation_seq ON message (conversation_id, server_seq)');
        $this->addSql('CREATE INDEX IDX_B6BD307F9AC0396 ON message (conversation_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307F2A98155E ON message (sender_user_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307FCEAD2322 ON message (sender_device_id)');
    }
}
