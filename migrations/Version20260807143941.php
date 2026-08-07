<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma initial de Locket (voir docs/API.md §1).
 */
final class Version20260807143941 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial : instance, membres, appareils, invitations, conversations, clés, messages, médias et abonnements push.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE attachment (
              id BLOB NOT NULL,
              media_id VARCHAR(32) NOT NULL,
              path VARCHAR(255) NOT NULL,
              bytes BIGINT NOT NULL,
              created_at DATETIME NOT NULL,
              expires_at DATETIME NOT NULL,
              linked BOOLEAN NOT NULL,
              owner_id BLOB NOT NULL,
              conversation_id BLOB DEFAULT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_795FD9BB7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_795FD9BB9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_795FD9BB7E3C61F9 ON attachment (owner_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BB9AC0396 ON attachment (conversation_id)');
        $this->addSql('CREATE INDEX idx_attachment_expires_at ON attachment (expires_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_attachment_media_id ON attachment (media_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation (
              id BLOB NOT NULL,
              type VARCHAR(16) NOT NULL,
              title VARCHAR(128) DEFAULT NULL,
              current_epoch INTEGER NOT NULL,
              member_set_version INTEGER NOT NULL,
              direct_key VARCHAR(128) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_conversation_direct_key ON conversation (direct_key)');
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation_key (
              id BLOB NOT NULL,
              epoch INTEGER NOT NULL,
              wrap BLOB NOT NULL,
              created_at DATETIME NOT NULL,
              conversation_id BLOB NOT NULL,
              recipient_device_id BLOB NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_F82159489AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_F821594893271B9C FOREIGN KEY (recipient_device_id) REFERENCES device (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_F82159489AC0396 ON conversation_key (conversation_id)');
        $this->addSql('CREATE INDEX IDX_F821594893271B9C ON conversation_key (recipient_device_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_key_device_conversation ON conversation_key (
              recipient_device_id, conversation_id
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_key_conversation_epoch_device ON conversation_key (
              conversation_id, epoch, recipient_device_id
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation_member (
              id BLOB NOT NULL,
              joined_at DATETIME NOT NULL,
              left_at DATETIME DEFAULT NULL,
              conversation_id BLOB NOT NULL,
              user_id BLOB NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_422840B89AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_422840B8A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_422840B89AC0396 ON conversation_member (conversation_id)');
        $this->addSql('CREATE INDEX IDX_422840B8A76ED395 ON conversation_member (user_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_member_conversation_user ON conversation_member (conversation_id, user_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE device (
              id BLOB NOT NULL,
              device_id VARCHAR(32) NOT NULL,
              ik_pub BLOB NOT NULL,
              sk_pub BLOB NOT NULL,
              status VARCHAR(16) NOT NULL,
              approval_cert BLOB DEFAULT NULL,
              label VARCHAR(64) NOT NULL,
              created_at DATETIME NOT NULL,
              approved_at DATETIME DEFAULT NULL,
              last_seen_at DATETIME DEFAULT NULL,
              user_id BLOB NOT NULL,
              approved_by_id BLOB DEFAULT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_92FB68EA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_92FB68E2D234F6A FOREIGN KEY (approved_by_id) REFERENCES device (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_92FB68EA76ED395 ON device (user_id)');
        $this->addSql('CREATE INDEX IDX_92FB68E2D234F6A ON device (approved_by_id)');
        $this->addSql('CREATE INDEX idx_device_user_status ON device (user_id, status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_device_id ON device (device_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE instance (
              id SMALLINT NOT NULL,
              name VARCHAR(128) NOT NULL,
              installed_at DATETIME DEFAULT NULL,
              vapid_public_key VARCHAR(255) DEFAULT NULL,
              vapid_private_key VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE invitation (
              id BLOB NOT NULL,
              invite_id_hash VARCHAR(32) NOT NULL,
              pseudo VARCHAR(64) NOT NULL,
              display_name VARCHAR(64) NOT NULL,
              purpose VARCHAR(16) NOT NULL,
              created_at DATETIME NOT NULL,
              expires_at DATETIME NOT NULL,
              used_at DATETIME DEFAULT NULL,
              revoked_at DATETIME DEFAULT NULL,
              target_user_id BLOB DEFAULT NULL,
              created_by_id BLOB NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_F11D61A26C066AFE FOREIGN KEY (target_user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_F11D61A2B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_F11D61A26C066AFE ON invitation (target_user_id)');
        $this->addSql('CREATE INDEX IDX_F11D61A2B03A8386 ON invitation (created_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invitation_invite_id_hash ON invitation (invite_id_hash)');
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
        $this->addSql('CREATE INDEX IDX_B6BD307F9AC0396 ON message (conversation_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307F2A98155E ON message (sender_user_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307FCEAD2322 ON message (sender_device_id)');
        $this->addSql('CREATE INDEX idx_message_conversation_seq ON message (conversation_id, server_seq)');
        $this->addSql('CREATE INDEX idx_message_expires_at ON message (expires_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_message_id ON message (id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE push_subscription (
              id BLOB NOT NULL,
              endpoint VARCHAR(512) NOT NULL,
              p256dh VARCHAR(255) NOT NULL,
              auth VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL,
              failures INTEGER NOT NULL,
              device_id BLOB NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_562830F394A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_push_device ON push_subscription (device_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "user" (
              id BLOB NOT NULL,
              pseudo VARCHAR(64) NOT NULL,
              display_name VARCHAR(64) NOT NULL,
              pin_hash VARCHAR(255) NOT NULL,
              email VARCHAR(180) DEFAULT NULL,
              is_admin BOOLEAN NOT NULL,
              created_at DATETIME NOT NULL,
              disabled_at DATETIME DEFAULT NULL,
              failed_attempts INTEGER NOT NULL,
              locked_until DATETIME DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_pseudo ON "user" (pseudo)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON "user" (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE attachment');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE conversation_key');
        $this->addSql('DROP TABLE conversation_member');
        $this->addSql('DROP TABLE device');
        $this->addSql('DROP TABLE instance');
        $this->addSql('DROP TABLE invitation');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE push_subscription');
        $this->addSql('DROP TABLE "user"');
    }
}
