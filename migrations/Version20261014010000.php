<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist managed project identity, immutable execution provenance and original idempotent mutation responses.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Project registry persistence requires PostgreSQL.');
        $this->addSql('CREATE TABLE managed_project (id UUID NOT NULL PRIMARY KEY, name VARCHAR(120) NOT NULL, repository_url VARCHAR(2048) DEFAULT NULL, repository_identity VARCHAR(160) DEFAULT NULL, runtimes JSONB NOT NULL, version INT NOT NULL CHECK (version > 0), created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, CONSTRAINT managed_project_runtimes CHECK (jsonb_typeof(runtimes) = \'array\'))');
        $this->addSql('CREATE TABLE execution_record (id UUID NOT NULL PRIMARY KEY, project_id UUID NOT NULL REFERENCES managed_project (id), source JSONB NOT NULL, environment JSONB NOT NULL, producer JSONB NOT NULL, native_identity JSONB NOT NULL, observed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, CONSTRAINT execution_source_object CHECK (jsonb_typeof(source) = \'object\'), CONSTRAINT execution_environment_object CHECK (jsonb_typeof(environment) = \'object\'), CONSTRAINT execution_producer_object CHECK (jsonb_typeof(producer) = \'object\'), CONSTRAINT execution_native_identity_object CHECK (jsonb_typeof(native_identity) = \'object\'))');
        $this->addSql('CREATE INDEX execution_record_project_id_idx ON execution_record (project_id, id)');
        // JSON preserves the original envelope's key order for exact application-service replays.
        $this->addSql('CREATE TABLE registry_request (id UUID NOT NULL PRIMARY KEY, action VARCHAR(32) NOT NULL, project_id UUID NOT NULL REFERENCES managed_project (id), resource_id UUID NOT NULL, payload_sha256 CHAR(64) NOT NULL, response JSON NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, CONSTRAINT registry_request_action CHECK (action IN (\'register\', \'update\', \'record_execution\')), CONSTRAINT registry_request_response_object CHECK (json_typeof(response) = \'object\'))');
        $this->addSql('CREATE INDEX registry_request_created_at_idx ON registry_request (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE registry_request');
        $this->addSql('DROP TABLE execution_record');
        $this->addSql('DROP TABLE managed_project');
    }
}
