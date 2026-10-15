<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The whole schema: readers, letters, and the messenger queue (auto_setup=0,
 * so that the consumer never creates tables behind the migrations' back).
 */
final class Version20261015221859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subscribers, newsletters, messenger queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE newsletter (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, subject VARCHAR(200) NOT NULL, body CLOB NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, scheduled_at DATETIME DEFAULT NULL, releve_number INTEGER DEFAULT NULL, sent_at DATETIME DEFAULT NULL, recipients INTEGER NOT NULL)');
        $this->addSql('CREATE TABLE subscriber (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(254) NOT NULL, status VARCHAR(16) NOT NULL, consent_version VARCHAR(32) NOT NULL, requested_at DATETIME NOT NULL, confirmed_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AD005B69E7927C74 ON subscriber (email)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE newsletter');
        $this->addSql('DROP TABLE subscriber');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
