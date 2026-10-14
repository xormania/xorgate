<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014170000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist scoped original report evidence, durable imports and native JUnit observations.'; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Report intake requires PostgreSQL.');
        $this->addSql('ALTER TABLE execution_record ADD CONSTRAINT execution_record_project_identity UNIQUE (project_id, id)');
        $this->addSql('CREATE TABLE report_artifact (id UUID PRIMARY KEY, project_id UUID NOT NULL, execution_id UUID NOT NULL, filename VARCHAR(255), media_type VARCHAR(120), sha256 CHAR(64) NOT NULL, bytes BIGINT NOT NULL CHECK (bytes >= 0), created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, expired_at TIMESTAMP(6) WITH TIME ZONE, UNIQUE(project_id, execution_id, id), FOREIGN KEY(project_id, execution_id) REFERENCES execution_record(project_id, id))');
        $this->addSql('CREATE INDEX report_artifact_digest ON report_artifact (sha256)');
        $this->addSql("CREATE TABLE report_import (id UUID PRIMARY KEY, project_id UUID NOT NULL, execution_id UUID NOT NULL, artifact_id UUID NOT NULL, request_id UUID NOT NULL, identity_version INT NOT NULL DEFAULT 1, request_digest CHAR(64) NOT NULL, report_role VARCHAR(80) NOT NULL, format VARCHAR(80) NOT NULL, source_context JSONB NOT NULL, status VARCHAR(16) NOT NULL CHECK (status IN ('queued','running','completed','failed')), parser_name VARCHAR(80), parser_version VARCHAR(120), parser_outcome VARCHAR(32), valid_document BOOLEAN, summary JSONB, limitations JSONB NOT NULL DEFAULT '[]', error_code VARCHAR(80), error_message VARCHAR(255), claim_token UUID, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, started_at TIMESTAMP(6) WITH TIME ZONE, completed_at TIMESTAMP(6) WITH TIME ZONE, UNIQUE(project_id, execution_id, report_role, request_id), FOREIGN KEY(project_id, execution_id, artifact_id) REFERENCES report_artifact(project_id, execution_id, id))");
        $this->addSql('CREATE INDEX report_import_execution_page ON report_import (project_id, execution_id, id)');
        $this->addSql('CREATE INDEX report_import_retention ON report_import (status, completed_at)');
        $this->addSql('CREATE TABLE report_suite (operation_id UUID NOT NULL REFERENCES report_import(id) ON DELETE CASCADE, suite_key VARCHAR(1024) NOT NULL, data JSONB NOT NULL, PRIMARY KEY(operation_id, suite_key))');
        $this->addSql('CREATE TABLE report_case (id UUID PRIMARY KEY, operation_id UUID NOT NULL REFERENCES report_import(id) ON DELETE CASCADE, case_key VARCHAR(1024) NOT NULL, data JSONB NOT NULL, UNIQUE(operation_id, case_key))');
        $this->addSql('CREATE INDEX report_case_operation_page ON report_case (operation_id, id)');
    }

    public function down(Schema $schema): void
    {
        foreach (['report_case','report_suite','report_import','report_artifact'] as $table) { $this->addSql('DROP TABLE '.$table); }
        $this->addSql('ALTER TABLE execution_record DROP CONSTRAINT execution_record_project_identity');
    }
}
