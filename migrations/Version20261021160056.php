<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021160056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create portfolio, scan history, findings and issue tracking storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_event (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, actor VARCHAR(255) NOT NULL, action VARCHAR(255) NOT NULL, subject_id VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE configuration (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, values JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE finding (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, fingerprint VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, severity VARCHAR(255) NOT NULL, title TEXT NOT NULL, details JSON NOT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, repository_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX finding_severity ON finding (severity, resolved_at)');
        $this->addSql('CREATE UNIQUE INDEX finding_identity ON finding (repository_id, fingerprint)');
        $this->addSql('CREATE INDEX IDX_A719133650C9D4F7 ON finding (repository_id)');
        $this->addSql('CREATE TABLE issue_tracking (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, fingerprint VARCHAR(255) NOT NULL, fingerprints JSON NOT NULL, number VARCHAR(255) NOT NULL, url TEXT NOT NULL, status VARCHAR(255) NOT NULL, last_error TEXT DEFAULT NULL, last_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, repository_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX issue_deduplication ON issue_tracking (repository_id, fingerprint)');
        $this->addSql('CREATE INDEX IDX_7FD448B750C9D4F7 ON issue_tracking (repository_id)');
        $this->addSql('CREATE TABLE provider_connection (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, provider VARCHAR(255) NOT NULL, base_url VARCHAR(255) NOT NULL, encrypted_token TEXT NOT NULL, organizations JSON NOT NULL, included_repositories JSON NOT NULL, excluded_repositories JSON NOT NULL, branch VARCHAR(255) DEFAULT NULL, frequency_hours INT NOT NULL, active BOOLEAN NOT NULL, status VARCHAR(255) NOT NULL, last_error TEXT DEFAULT NULL, last_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_tested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, next_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE repository (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, external_id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, url TEXT NOT NULL, default_branch VARCHAR(255) NOT NULL, archived BOOLEAN NOT NULL, accessible BOOLEAN NOT NULL, last_commit VARCHAR(255) DEFAULT NULL, last_scan_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, latest_score INT DEFAULT NULL, confidence INT NOT NULL, coverage INT NOT NULL, connection_id UUID NOT NULL, latest_scan_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX repository_health ON repository (latest_score)');
        $this->addSql('CREATE UNIQUE INDEX repository_external_identity ON repository (connection_id, external_id)');
        $this->addSql('CREATE INDEX IDX_5CFE57CDDD03F01 ON repository (connection_id)');
        $this->addSql('CREATE INDEX IDX_5CFE57CD2B45DC6C ON repository (latest_scan_id)');
        $this->addSql('CREATE TABLE scan (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(255) NOT NULL, commit_hash VARCHAR(255) DEFAULT NULL, branch VARCHAR(255) NOT NULL, score INT DEFAULT NULL, confidence INT NOT NULL, coverage INT NOT NULL, results JSON NOT NULL, last_error TEXT DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, repository_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX scan_repository_created ON scan (repository_id, created_at)');
        $this->addSql('CREATE INDEX IDX_C4B3B3AE50C9D4F7 ON scan (repository_id)');
        $this->addSql('CREATE TABLE sync_job (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(255) NOT NULL, processed INT NOT NULL, failed INT NOT NULL, last_error TEXT DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, connection_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX sync_status_created ON sync_job (status, created_at)');
        $this->addSql('CREATE INDEX IDX_4596994BDD03F01 ON sync_job (connection_id)');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A719133650C9D4F7 FOREIGN KEY (repository_id) REFERENCES repository (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE issue_tracking ADD CONSTRAINT FK_7FD448B750C9D4F7 FOREIGN KEY (repository_id) REFERENCES repository (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE repository ADD CONSTRAINT FK_5CFE57CDDD03F01 FOREIGN KEY (connection_id) REFERENCES provider_connection (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE repository ADD CONSTRAINT FK_5CFE57CD2B45DC6C FOREIGN KEY (latest_scan_id) REFERENCES scan (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scan ADD CONSTRAINT FK_C4B3B3AE50C9D4F7 FOREIGN KEY (repository_id) REFERENCES repository (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sync_job ADD CONSTRAINT FK_4596994BDD03F01 FOREIGN KEY (connection_id) REFERENCES provider_connection (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finding DROP CONSTRAINT FK_A719133650C9D4F7');
        $this->addSql('ALTER TABLE issue_tracking DROP CONSTRAINT FK_7FD448B750C9D4F7');
        $this->addSql('ALTER TABLE repository DROP CONSTRAINT FK_5CFE57CDDD03F01');
        $this->addSql('ALTER TABLE repository DROP CONSTRAINT FK_5CFE57CD2B45DC6C');
        $this->addSql('ALTER TABLE scan DROP CONSTRAINT FK_C4B3B3AE50C9D4F7');
        $this->addSql('ALTER TABLE sync_job DROP CONSTRAINT FK_4596994BDD03F01');
        $this->addSql('DROP TABLE audit_event');
        $this->addSql('DROP TABLE configuration');
        $this->addSql('DROP TABLE finding');
        $this->addSql('DROP TABLE issue_tracking');
        $this->addSql('DROP TABLE provider_connection');
        $this->addSql('DROP TABLE repository');
        $this->addSql('DROP TABLE scan');
        $this->addSql('DROP TABLE sync_job');
    }
}
