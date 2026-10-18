<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist native report attempts, derived annotations with explicit link status, and measured import processing time.'; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Report intake requires PostgreSQL.');
        $this->addSql('ALTER TABLE report_import ADD processing_ms INT CHECK (processing_ms >= 0)');
        $this->addSql('CREATE TABLE report_attempt (id UUID PRIMARY KEY, operation_id UUID NOT NULL REFERENCES report_import(id) ON DELETE CASCADE, case_key VARCHAR(1024) NOT NULL, attempt_key VARCHAR(1024) NOT NULL, retry INT, data JSONB NOT NULL, UNIQUE(operation_id, attempt_key))');
        $this->addSql('CREATE INDEX report_attempt_operation_page ON report_attempt (operation_id, id)');
        $this->addSql('CREATE INDEX report_attempt_operation_case ON report_attempt (operation_id, case_key)');
        $this->addSql("CREATE TABLE report_annotation (id UUID PRIMARY KEY, operation_id UUID NOT NULL REFERENCES report_import(id) ON DELETE CASCADE, project_id UUID NOT NULL, execution_id UUID NOT NULL, annotation_key VARCHAR(1024) NOT NULL, kind VARCHAR(40) NOT NULL, target JSONB NOT NULL, data JSONB NOT NULL, match_status VARCHAR(16) NOT NULL CHECK (match_status IN ('linked','unmatched','ambiguous','not_applicable')), match_reason VARCHAR(80), target_operation_id UUID REFERENCES report_import(id) ON DELETE SET NULL, target_case_key VARCHAR(1024), target_attempt_key VARCHAR(1024), linked_at TIMESTAMP(6) WITH TIME ZONE, UNIQUE(operation_id, annotation_key))");
        $this->addSql('CREATE INDEX report_annotation_operation_page ON report_annotation (operation_id, id)');
        $this->addSql('CREATE INDEX report_annotation_execution_status ON report_annotation (project_id, execution_id, match_status)');
        $this->addSql('CREATE INDEX report_annotation_target ON report_annotation (target_operation_id, target_attempt_key)');
        // Linking looks up native cases by the identities derived files carry, once per annotation.
        $this->addSql("CREATE INDEX report_case_spec ON report_case (operation_id, (data->>'spec_id'))");
        $this->addSql("CREATE INDEX report_case_location ON report_case (operation_id, (data->>'file'), (data->>'line'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX report_case_location');
        $this->addSql('DROP INDEX report_case_spec');
        $this->addSql('DROP TABLE report_annotation');
        $this->addSql('DROP TABLE report_attempt');
        $this->addSql('ALTER TABLE report_import DROP COLUMN processing_ms');
    }
}
