<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260807162910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversation_key ADD COLUMN attestation BLOB NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__conversation_key AS SELECT id, epoch, wrap, created_at, conversation_id, recipient_device_id FROM conversation_key');
        $this->addSql('DROP TABLE conversation_key');
        $this->addSql('CREATE TABLE conversation_key (id BLOB NOT NULL, epoch INTEGER NOT NULL, wrap BLOB NOT NULL, created_at DATETIME NOT NULL, conversation_id BLOB NOT NULL, recipient_device_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_F82159489AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F821594893271B9C FOREIGN KEY (recipient_device_id) REFERENCES device (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO conversation_key (id, epoch, wrap, created_at, conversation_id, recipient_device_id) SELECT id, epoch, wrap, created_at, conversation_id, recipient_device_id FROM __temp__conversation_key');
        $this->addSql('DROP TABLE __temp__conversation_key');
        $this->addSql('CREATE INDEX IDX_F82159489AC0396 ON conversation_key (conversation_id)');
        $this->addSql('CREATE INDEX IDX_F821594893271B9C ON conversation_key (recipient_device_id)');
        $this->addSql('CREATE INDEX idx_key_device_conversation ON conversation_key (recipient_device_id, conversation_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_key_conversation_epoch_device ON conversation_key (conversation_id, epoch, recipient_device_id)');
    }
}
