<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Création de la table `external_links` (lanceur d'apps du header).
 *
 * Cf. `docs/specifications.md` §3.12 et `docs/modele-de-donnees.md` §3.12.
 */
final class Version20260512090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Crée la table external_links (lanceur d'apps du header) avec un index sur (enabled, position) pour le tri du dropdown utilisateur.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE external_links (
                id UUID NOT NULL,
                label VARCHAR(64) NOT NULL,
                url VARCHAR(1024) NOT NULL,
                icon VARCHAR(128) DEFAULT NULL,
                description VARCHAR(255) DEFAULT NULL,
                position INTEGER NOT NULL,
                enabled BOOLEAN DEFAULT TRUE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_external_links_enabled_position ON external_links (enabled, position)');

        $this->addSql("COMMENT ON COLUMN external_links.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN external_links.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN external_links.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE external_links');
    }
}
