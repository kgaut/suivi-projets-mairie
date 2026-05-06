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

- **Filtrage d'accès `OIDC_REQUIRED_GROUPS`** (defense in depth, cf. specs §5.3) :
  - `App\Security\OidcAccessGuard` — service qui parse la liste CSV des groupes requis et vérifie l'intersection avec les groupes Authentik de l'utilisateur. Si vide → throw `CustomUserMessageAuthenticationException` qui interrompt l'auth, et marque la projection locale `disabledAt` (préserve l'historique sans permettre une nouvelle session)
  - Branché dans `OidcUserProvider::ensureUserExists` après création/maj du user
  - `failure_path: /access-denied` sur le firewall OIDC pour rediriger en cas de rejet
  - Page `/access-denied` enrichie : icône, message dynamique récupéré via `AuthenticationUtils`, bouton "Se déconnecter"
  - Si `OIDC_REQUIRED_GROUPS=` (vide) → pas de filtrage côté app (mais Policy Binding Authentik reste en place)
  - Tests unitaires `OidcAccessGuardTest` (6 tests) couvrant config vide, intersection trouvée, rejet, parsing CSV avec espaces
- **Authentification OIDC via `drenso/symfony-oidc-bundle`** :
  - Bundle enregistré, `config/packages/drenso_oidc.yaml` qui lit `OIDC_WELL_KNOWN_URL` / `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` depuis l'env, PKCE activé (`code_challenge_method: S256`), cache jwks et well-known 1 h
  - `security.yaml` réécrit : firewall `main` avec authenticator `oidc:` drenso, provider applicatif `App\Security\OidcUserProvider`, access_control basique (`/login`, `/access-denied` publics, reste `ROLE_USER`)
  - `App\Security\OidcUserProvider` qui implémente `OidcUserProviderInterface` (drenso) et `UserProviderInterface` (Symfony) : réconciliation au login (création si nouveau, mise à jour des champs Authentik à chaque connexion : `username`, `email`, `displayName`, `groupsSnapshot`, `lastLoginAt`), calcul de `ROLE_ADMIN` si l'utilisateur est dans `OIDC_ADMIN_GROUP`
  - Routes `/login` (redirige vers Authentik via `OidcClientInterface::generateAuthorizationRedirect()`), `/logout` (intercepté par le firewall), `/access-denied` (page minimaliste, à enrichir issue #23)
  - Variables d'env `OIDC_WELL_KNOWN_URL` / `OIDC_REDIRECT_URI` / `OIDC_ADMIN_GROUP` etc. dans `.env` et `.env.example`
  - Tests unitaires `App\Tests\Security\OidcUserProviderTest` (7 tests, 30 assertions) couvrant création, mise à jour, ROLE_ADMIN, claim `groups` absent, loadUserByIdentifier, supportsClass
- **Entité `App\Domain\User`** (projection locale d'Authentik) — PK Uuid v7, `authentikId` unique, `username`, `email`, `displayName`, `roles`, `groupsSnapshot`, `lastLoginAt`, `disabledAt`, attributs avatar (`avatarPath`, `authentikAvatarSourceUrl`, `authentikAvatarPath`, `authentikAvatarFetchedAt`), `avatarSource`, `gravatarAllowed`, `createdAt`, `updatedAt`. Implémente `Symfony\Component\Security\Core\User\UserInterface` avec `getUserIdentifier()` retournant `authentikId` (clé stable)
- **Enum `App\Domain\Enum\AvatarSource`** : `AUTO` / `LOCAL` / `AUTHENTIK` / `GRAVATAR` / `INITIALS`
- **`App\Infrastructure\Repository\UserRepository`** avec `findOneByAuthentikId()` pour la réconciliation au login
- `doctrine/doctrine-fixtures-bundle` en require-dev (placeholder `AppFixtures` vide, sera enrichi au Lot 1) — corrige `make install` qui appelait `doctrine:fixtures:load` sans bundle installé
- Migration Doctrine `Version20260506144756` créant la table `users` (Postgres : `JSON`, `UUID`, `TIMESTAMP WITHOUT TIME ZONE`, index unique `authentik_id`, index `email`)
- Configuration Doctrine pointée vers `src/Domain` (au lieu de `src/Entity` par défaut), conforme aux specs §5.2
- Bundles installés : `symfony/orm-pack`, `doctrine/doctrine-migrations-bundle`, `symfony/uid`, `symfony/security-bundle`, `symfony/test-pack` (phpunit en require-dev)
- Suite de tests unitaires `App\Tests\Domain\UserTest` (9 tests, 29 assertions, 100 %)
- `phpunit.dist.xml` testsuites séparées `Unit` (tests/Domain, tests/Application) et `Functional` (tests/Functional, tests/Controller)
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
- `docker-compose.prod.yml` complet — services `app` (FrankenPHP mode worker), `worker` (Messenger consume), `migrate` (one-shot bloquant), `postgres:16-alpine`, `redis:7-alpine` ; networks isolés `internal_net` (services) et `caddy_net` (proxy externe partagé) ; volumes `pgdata`, `redisdata`, `uploads`, `logs` ; `app` et `worker` en `depends_on: { migrate: { condition: service_completed_successfully } }` (pas de trafic vers une base incohérente si la migration échoue) ; image GHCR taguée par `APP_VERSION` avec `build` local en fallback
- **Tailwind CSS 4.1** via `symfonycasts/tailwind-bundle` (binaire standalone, pas de Node) — `assets/styles/app.css` avec `@import "tailwindcss";`, build via `bin/console tailwind:build` (ou `tailwind:build --watch` en dev)
- **AssetMapper** Symfony natif (pas de Node, pas de Webpack) — `assets/`, `importmap.php`, point d'entrée `app.js`
- **Symfony UX Turbo** (`@hotwired/turbo` 8.x) pour la navigation Turbo et les frames
- **Symfony UX Stimulus** (`@hotwired/stimulus` 3.x) pour les controllers JS côté client
- **Symfony UX Live Components** pour les composants Twig réactifs côté serveur
- `src/Twig/Components/HelloLive.php` + `templates/components/HelloLive.html.twig` : démo Live Component (compteur incrémenté côté serveur)
- `assets/controllers/hello_controller.js` : démo Stimulus côté client (toggle de message)
- Page d'accueil refondue avec Tailwind + démos Stimulus et Live Component côté à côté
- Layout Twig responsive mobile-first : `templates/_partials/_header.html.twig` (logo, burger menu CSS-only ≥ 1024 px), `_nav.html.twig`, `_footer.html.twig`. Skip link "Aller au contenu" pour l'accessibilité (RGAA), structure HTML5 sémantique (`<header>`, `<main>`, `<footer>`, `<nav>` avec `aria-label`), `lang="fr"`, `theme-color`. Burger en `<input type="checkbox" class="peer">` + `peer-checked:flex` Tailwind (pas de JS, accessible via label associé)

### Fixed

- `templates/home/index.html.twig` : marqueurs de conflit git non résolus (`<<<<<<<`, `=======`, `>>>>>>>`) laissés par le merge auto de la PR #17 sur le main contenant déjà la PR #16. Contenu consolidé : démos Stimulus + Live Component (apport #16) sans le `<main>` (qui vient désormais du layout via `base.html.twig`, apport #17)

### Changed

- `.editorconfig` enrichi : règles dédiées YAML/JSON/Twig/JS/CSS (2 espaces), Markdown (2 espaces, trailing whitespace conservé), Makefile (tabulations exigées par make)

### Added

- `.gitattributes` à la racine : normalisation eol=lf, déclaration des binaires (png, jpg, fonts, archives, etc.), `export-ignore` sur les fichiers de tooling/doc pour alléger les archives `git archive`, hints `linguist-*` pour les statistiques GitHub

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
