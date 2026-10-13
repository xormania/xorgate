<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record deliberate foundation artifact expiration separately from missing or corrupt evidence.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE foundation_probe ADD artifact_expired_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE foundation_probe DROP artifact_expired_at');
    }
}
