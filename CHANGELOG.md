# Changelog

Toutes les modifications notables de ce projet sont consignées dans ce fichier.

Le format suit [Keep a Changelog 1.1.0](https://keepachangelog.com/fr/1.1.0/), et le projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

Les sections possibles sous chaque version sont, dans l'ordre :

- **Added** — nouvelles fonctionnalités
- **Changed** — modifications de fonctionnalités existantes
- **Deprecated** — fonctionnalités bientôt retirées
- **Removed** — fonctionnalités retirées
- **Fixed** — corrections de bugs
- **Security** — corrections de vulnérabilités

À chaque PR, l'auteur ajoute (ou complète) une entrée sous la section `## [Unreleased]`. À la création d'un tag `vX.Y.Z`, le contenu de `[Unreleased]` est déplacé dans une nouvelle section `## [X.Y.Z] - YYYY-MM-DD`.

## [Unreleased]

### Added

- Cadrage opérationnel détaillé du Lot 0 (`docs/lots/lot-0-cadrage.md`) — découpage en 4 vagues (squelette technique, OIDC, administration, audit/qualité/CI), 6 décisions techniques validées (AssetMapper, drenso/symfony-oidc-bundle, pas de mode stub OIDC, un seul worker Messenger, nom Sentry `spm`, Mailpit en HTTP direct), plan d'attaque côté issues
- Pointer vers ce cadrage depuis `docs/roadmap.md` (Lot 0 marqué `🚧 en cours`)
- Bootstrap Symfony 7.4 LTS (php ^8.4) avec Flex : `composer.json`, `composer.lock`, `symfony.lock`, structure `bin/console`, `public/index.php`, `config/`, `templates/base.html.twig`
- Structure d'architecture `src/` : `Controller/`, `Domain/`, `Application/`, `Infrastructure/`, `Security/`, `Twig/` (cf. `docs/specifications.md` §5.2)
- Route `/` (`HomeController`) qui rend "Hello SPM" via Twig — premier signe de vie de l'application
- `.gitignore` : exclusion des overrides compose locaux (`docker-compose.yml`, `docker-compose.override.yml`)
- `Dockerfile` multi-stages (`base` / `dev` / `prod`) basé sur `dunglas/frankenphp:1-php8.4` avec extensions PHP nécessaires (intl, opcache, apcu, pdo_pgsql, redis, zip, composer)
- Confs PHP par stage : `docker/php/conf.d/zz-app.ini` (partagée), `docker/php/conf.d-dev/zz-dev.ini` (validate_timestamps + Xdebug optionnel), `docker/php/conf.d-prod/zz-prod.ini` (preload + opcache figé)
- `docker-compose.dev.yml` (renommé depuis `docker-compose.dev.yml.example`) — FrankenPHP en HTTPS sur `spm.localhost` (CA Caddy persistée), Postgres 16, Redis 7, Mailpit ; vars d'env `DATABASE_URL`/`REDIS_URL`/`MAILER_DSN` pré-câblées
- `.dockerignore` pour garder les builds rapides et l'image prod légère
- `Makefile` à la racine qui wrappe les commandes Docker, Symfony et qualité ; `make help` affiche les cibles regroupées par section (Démarrage, Installation, Développement, Tests, Qualité). Cibles destructrices (`clean`, `reset`) demandent une confirmation interactive ; cibles de qualité (`stan`, `cs`, `rector`, etc.) sont déclarées même si les outils ne sont pas encore installés (l'erreur sera explicite à l'appel)

## [0.0.1] - 2026-05-06

Cadrage initial du projet — pas de code applicatif, uniquement les documents de référence.

### Added

- Spécifications fonctionnelles et techniques (`docs/specifications.md`) — modèle métier, sécurité, RGPD, RGAA, 16 décisions tranchées (§8)
- Modèle de données Doctrine (`docs/modele-de-donnees.md`) — 14 entités principales avec types PHP/SQL, relations, index
- Roadmap et lots de livraison (`docs/roadmap.md`) — 7 lots planifiés (`v0.1.0` à `v0.7.0`)
- Documentation Authentik OIDC (`docs/authentik.md`) — configuration pas-à-pas, `OIDC_ADMIN_GROUP=admin_spm`, méga-groupe `spm_users`
- Documentation déploiement Docker (`docs/deploiement.md`) — Caddy seul, networks `internal_net` / `caddy_net`, service `migrate` one-shot
- Documentation environnement dev local (`docs/local-dev.md`) — TLS local sur `https://spm.localhost` via Caddy embarqué
- Documentation outils qualité (`docs/qualite.md`) — PHPStan, PHP-CS-Fixer, Twig CS Fixer, Rector, Deptrac, GrumPHP, Sentry
- Documentation workflow de contribution (`docs/workflow.md`) — convention `feature/<n°>-<slug>`, Conventional Commits FR, squash merge, tags par lot
- Cadrage opérationnel détaillé du Lot 0 (`docs/lots/lot-0-cadrage.md`) — 4 vagues, 6 décisions techniques validées
- Templates `.env.example` et `docker-compose.dev.yml.example`
- Pointer Claude Code (`CLAUDE.md`) — état du projet, ordre de lecture, conventions, décisions structurantes
- Licence AGPL-3.0 (`LICENSE`)
- README.md

[Unreleased]: https://github.com/kgaut/suivi-projets-mairie/compare/v0.0.1...HEAD
[0.0.1]: https://github.com/kgaut/suivi-projets-mairie/releases/tag/v0.0.1
