<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The admin's passkeys.
 */
final class Version20261016101236 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The admin passkeys (WebAuthn public keys, never the private ones)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE passkey (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, webauthn_id VARCHAR(255) NOT NULL, name VARCHAR(64) NOT NULL, record CLOB NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_85347C20DC206A ON passkey (webauthn_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE passkey');
    }
}
