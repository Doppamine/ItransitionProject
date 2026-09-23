<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GIN full-text indexes for Position and current CV source values.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');
        $this->addSql("CREATE INDEX idx_position_fts ON position USING GIN (to_tsvector('simple'::regconfig, coalesce(title, '') || ' ' || coalesce(short_description, '')))");
        $this->addSql("CREATE INDEX idx_profile_attribute_value_fts ON profile_attribute_value USING GIN (to_tsvector('simple'::regconfig, coalesce(text_value, '')))");
        $this->addSql("CREATE INDEX idx_attribute_option_fts ON attribute_option USING GIN (to_tsvector('simple'::regconfig, coalesce(label, '')))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_attribute_option_fts');
        $this->addSql('DROP INDEX idx_profile_attribute_value_fts');
        $this->addSql('DROP INDEX idx_position_fts');
    }
}
