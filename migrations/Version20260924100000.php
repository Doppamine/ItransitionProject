<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist supported UI locale and Bootstrap theme preferences for every user.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');
        $this->addSql("ALTER TABLE app_user ADD locale VARCHAR(2) DEFAULT 'en' NOT NULL");
        $this->addSql("ALTER TABLE app_user ADD theme VARCHAR(5) DEFAULT 'light' NOT NULL");
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT chk_app_user_locale CHECK (locale IN ('en', 'ru'))");
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT chk_app_user_theme CHECK (theme IN ('light', 'dark'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT chk_app_user_theme');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT chk_app_user_locale');
        $this->addSql('ALTER TABLE app_user DROP theme');
        $this->addSql('ALTER TABLE app_user DROP locale');
    }
}
