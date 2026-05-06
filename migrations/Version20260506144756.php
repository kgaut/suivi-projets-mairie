<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Création de la table `users` (projection locale d'Authentik).
 *
 * Cf. `docs/specifications.md` §3.8 et `docs/modele-de-donnees.md` §3.8.
 */
final class Version20260506144756 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table users (projection Authentik) avec les champs de base, les rôles, les snapshots de groupes et les attributs avatar.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id UUID NOT NULL,
                authentik_id VARCHAR(64) NOT NULL,
                username VARCHAR(128) NOT NULL,
                email VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                roles JSON NOT NULL,
                groups_snapshot JSON NOT NULL,
                last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                disabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                avatar_path VARCHAR(255) DEFAULT NULL,
                authentik_avatar_source_url VARCHAR(1024) DEFAULT NULL,
                authentik_avatar_path VARCHAR(255) DEFAULT NULL,
                authentik_avatar_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                avatar_source VARCHAR(16) NOT NULL,
                gravatar_allowed BOOLEAN DEFAULT TRUE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_users_email ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX uq_users_authentik_id ON users (authentik_id)');

        // Hints Doctrine pour relire les types personnalisés (uuid, datetime_immutable)
        $this->addSql("COMMENT ON COLUMN users.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN users.last_login_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN users.disabled_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN users.authentik_avatar_fetched_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN users.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN users.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE users');
    }
}
