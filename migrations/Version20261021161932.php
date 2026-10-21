<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021161932 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track updates, repository scope and issue refresh deadlines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_event ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE audit_event SET updated_at = created_at');
        $this->addSql('ALTER TABLE audit_event ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE configuration ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE configuration SET updated_at = created_at');
        $this->addSql('ALTER TABLE configuration ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE finding ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE finding SET updated_at = created_at');
        $this->addSql('ALTER TABLE finding ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE issue_tracking ADD next_refresh_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE issue_tracking ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE issue_tracking SET updated_at = created_at');
        $this->addSql('ALTER TABLE issue_tracking ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE provider_connection ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE provider_connection SET updated_at = created_at');
        $this->addSql('ALTER TABLE provider_connection ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE repository ADD monitored BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('ALTER TABLE repository ALTER monitored DROP DEFAULT');
        $this->addSql('ALTER TABLE repository ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE repository SET updated_at = created_at');
        $this->addSql('ALTER TABLE repository ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE scan ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE scan SET updated_at = created_at');
        $this->addSql('ALTER TABLE scan ALTER updated_at SET NOT NULL');
        $this->addSql('ALTER TABLE sync_job ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE sync_job SET updated_at = created_at');
        $this->addSql('ALTER TABLE sync_job ALTER updated_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_event DROP updated_at');
        $this->addSql('ALTER TABLE configuration DROP updated_at');
        $this->addSql('ALTER TABLE finding DROP updated_at');
        $this->addSql('ALTER TABLE issue_tracking DROP next_refresh_at');
        $this->addSql('ALTER TABLE issue_tracking DROP updated_at');
        $this->addSql('ALTER TABLE provider_connection DROP updated_at');
        $this->addSql('ALTER TABLE repository DROP monitored');
        $this->addSql('ALTER TABLE repository DROP updated_at');
        $this->addSql('ALTER TABLE scan DROP updated_at');
        $this->addSql('ALTER TABLE sync_job DROP updated_at');
    }
}
