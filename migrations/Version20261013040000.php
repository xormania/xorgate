<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist operational foundation probes and explicitly provision Doctrine Messenger queue storage.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This foundation migration requires PostgreSQL.');

        $this->addSql("CREATE TABLE foundation_probe (id UUID NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, artifact_path VARCHAR(255) DEFAULT NULL, artifact_sha256 VARCHAR(64) DEFAULT NULL, artifact_bytes BIGINT DEFAULT NULL, error_code VARCHAR(64) DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT foundation_probe_status CHECK (status IN ('queued', 'completed', 'failed')))");

        // Mirrors symfony/doctrine-messenger 8.1 Transport/Connection.php schema; auto_setup is disabled.
        $this->addSql('CREATE TABLE messenger_messages (id BIGSERIAL NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX messenger_messages_queue_idx ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE foundation_probe');
    }
}
