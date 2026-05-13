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

- **Section administration** (`/admin/*`, verrouillée par `ROLE_ADMIN` via `access_control` Symfony) — Vague 3 du Lot 0 :
  - **Tableau de bord** (`/admin`) : compteurs utilisateurs (total + actifs) et liens externes, raccourcis vers les sections de gestion
  - **Liste utilisateurs** (`/admin/users`) avec filtres combinables (recherche full-text sur `username`/`email`/`displayName`, rôle, groupe Authentik, statut actif/désactivé) et tri par `displayName`. Affiche les groupes Authentik en pills (3 visibles + compteur), la dernière connexion et le statut
  - **Fiche utilisateur** (`/admin/users/{id}`) : identifiants Authentik, rôles, groupes complets, dates de création / dernière connexion, statut. Bouton « Ouvrir dans Authentik ↗ » construit depuis `OIDC_WELL_KNOWN_URL` (recherche sur le `sub`). Espace réservé pour l'historique des contributions (Lot 1+)
  - **Layout admin dédié** (`templates/admin/_layout.html.twig` + `_sidebar.html.twig`) : sidebar avec items actifs détectés via le `_route` courant, badge « Mode administration » + lien de retour, breadcrumb surchargeable par bloc, gestion des flashs `success`/`error`
- **Gestion des liens externes** (entité `App\Domain\ExternalLink`, table `external_links`, migration `Version20260512090000`) : CRUD admin complet (`/admin/external-links/{,new,{id}/edit,{id}/toggle,{id}}`) avec validation Symfony Form (label 1-64 chars, URL HTTPS via `Assert\Url`, position ≥ 0). Toggle et delete protégés par jeton CSRF par-lien. Cf. specs §3.12 et `docs/modele-de-donnees.md` §3.12
- **Composant « lanceur d'apps »** dans le header (`templates/_partials/_app_launcher.html.twig`) : icône grille → dropdown desktop / panneau plein-écran mobile, grille 2 colonnes avec icône (emoji ou première lettre du libellé) + libellé tronqué. Liens en `target="_blank" rel="noopener noreferrer"` et tooltip via `title`. Visible uniquement si l'user est authentifié ET qu'il existe au moins un lien actif. Stimulus controller `app_launcher` (toggle, clic-outside, touche Escape) sur le même modèle que `user_menu`
- **Extension Twig `app_launcher_links()`** (`App\Twig\AppLauncherExtension`) qui expose les liens actifs au template via l'interface applicative — découpe l'accès depuis Twig
- **Ports applicatifs Repository** (`App\Application\User\UserRepositoryInterface`, `App\Application\ExternalLink\ExternalLinkRepositoryInterface`) implémentés par les repositories Doctrine. Les contrôleurs et la Twig extension dépendent désormais de ces interfaces — alignement sur le pattern existant `AttachmentStorageInterface`, isolation Application ⇏ Infrastructure (cf. `docs/qualite.md` §3). Aliases déclarés dans `config/services.yaml`
- **DTO de filtre `App\Application\User\UserFilter`** : construit depuis la query string par `fromQuery()` (normalise les valeurs vides en `null`, valide le statut), constantes `STATUS_ALL` / `STATUS_ACTIVE` / `STATUS_DISABLED`
- **Tests** :
  - Unit `App\Tests\Domain\ExternalLinkTest` (5 tests : défauts, UUID v7, setters qui bumpent `updatedAt`, idempotence enable/disable, nullable explicite)
  - Unit `App\Tests\Application\User\UserFilterTest` (5 tests : défauts, normalisation, statut inconnu, chaînes vides, types non-string)
  - Unit `App\Tests\Form\ExternalLinkInputTest` (4 tests : `fromEntity`, `applyTo` avec normalisation, `toNewEntity`, échec pré-condition)
  - Unit `App\Tests\Twig\AppLauncherExtensionTest` (2 tests : délégation au port, fonction Twig exposée)
  - Functional `App\Tests\Functional\Admin\AdminAccessTest` (4 tests : redirection anonyme, 403 non-admin, 200 admin sur dashboard + sous-sections)
  - Functional `App\Tests\Functional\Admin\AdminUsersControllerTest` (5 tests : liste complète, filtre recherche, filtre statut, filtre rôle, fiche détail)
  - Functional `App\Tests\Functional\Admin\AdminExternalLinksControllerTest` (8 tests : index avec liens, état vide, création, validation URL, édition, toggle, delete, rejet CSRF sur toggle et delete)
- **Dépendances** : `symfony/form` ^7.4 + `symfony/validator` ^7.4 (CRUD admin)
- **Outillage qualité branché à la racine** : `phpstan.neon.dist` (level 9 + extensions Symfony / Doctrine / PHPUnit auto-installées via `phpstan/extension-installer`), `.php-cs-fixer.dist.php` (preset Symfony + Symfony:risky + PSR-12 + `@PHP84Migration` + `@PHPUnit100Migration:risky` ; `yoda_style` désactivé volontairement), `rector.php` (`withPhpSets(php84: true)` + `withComposerBased(symfony, doctrine, phpunit)` + `withPreparedSets(deadCode, codeQuality, codingStyle, typeDeclarations, privatization, earlyReturn, instanceOf)`), `.twig-cs-fixer.dist.php` (standards Twig CS Fixer + Symfony), `deptrac.yaml` (couches Controller / Application / Domain / Infrastructure / Security / Twig / DataFixtures / Vendor avec ruleset strict `Controller→Application→Domain` et tolérance documentée `Domain→Infrastructure` pour `#[ORM\Entity(repositoryClass)]`)
- **GrumPHP** (`phpro/grumphp` ^2.20) : `grumphp.yml` à la racine. Hooks pre-commit (composer + phpcsfixer + twigcsfixer + yamllint) et testsuite prepush (phpstan + securitychecker_symfony). Hook git auto-installé via `composer install`. Contournement ponctuel via `git commit --no-verify`
- **Sentry** (`sentry/sentry-symfony` ^5.10) : bundle enregistré dans `config/bundles.php`, `config/packages/sentry.yaml` (DSN via `SENTRY_DSN`, environment via `SENTRY_ENV`, release via `APP_VERSION`, `send_default_pii: false` pour le RGPD, traces 10 %, profiling désactivé v1, `NotFoundHttpException` et `AccessDeniedException` filtrés des envois Sentry, capture Messenger activée). Override `when@test` qui désactive Sentry sur la testsuite
- **Workflow CI GitHub Actions** (`.github/workflows/ci.yml`) — 3 jobs en parallèle (`lint`, `test`, `audit`) avec annulation automatique sur push successifs (`concurrency`), cache Composer, services Postgres 16 + Redis 7 sur le job `test`, `doctrine:schema:validate` + migrations + PHPUnit, `composer audit` + `rector --dry-run` sur le job `audit`
- **Workflow Release GitHub Actions** (`.github/workflows/release.yml`) — sur tag `v*.*.*` : build Docker multi-arch (linux/amd64 + linux/arm64) du stage `prod` du `docker/Dockerfile`, push GHCR (`ghcr.io/kgaut/suivi-projets-mairie`) avec tags semver (`v0.1.0`, `0.1`, `0`, `latest` hors pré-release, `sha-xxxxx`), labels OCI (titre, description, licence AGPL-3.0, source, version), création GitHub Release avec extraction automatique de la section CHANGELOG correspondante
- **Infrastructure d'audit (préparation Lot 2)** : interface `App\Application\Event\AuditableEvent` (`category()`, `action()`, `subjectAuthentikId()`, `context()`, `occurredAt()`), classe abstraite `AbstractAuditableEvent` (occurredAt figé à l'instanciation), 5 événements `security` (`UserLoggedIn`, `UserLoggedOut`, `LoginFailed`, `AccessDenied`, `SessionExpired`) et 3 événements `user` (`UserFirstSeen`, `UserProfileUpdated`, `UserDisabled`). Subscriber `AuditableEventLogger` qui log dans le canal Monolog standard (vérification via `bin/console debug:event-dispatcher`). Persistance dans une table dédiée renvoyée au Lot 2 (cf. specs §3.10)
- Suite de tests `App\Tests\Application\Event\AuditableEventTest` (5 tests) + `AuditableEventLoggerTest` (2 tests, EventDispatcher en boîte avec `InMemoryLogger`)
- **Menu utilisateur dans le header** (`templates/_partials/_user_menu.html.twig`) : si non authentifié → bouton "Se connecter" vers `/login` ; si authentifié → avatar (`user|avatar(32)`) + displayName + dropdown (Mon profil, Administration si `ROLE_ADMIN`, séparateur, Se déconnecter via POST `/logout`). Stimulus controller `user_menu` gère ouverture/fermeture (clic, clic-outside, touche Escape) avec `aria-expanded` mis à jour
- **Page `/profile`** complète (`App\Controller\ProfileController`) : affichage (identifiants Authentik, dernier login, rôles, groupes en pills) + upload d'avatar local (jpg/png/webp, 2 Mo max, redimensionnement WebP 512×512 via GD) + suppression avatar + préférences (sélecteur `avatarSource`, toggle `gravatarAllowed` avec mention RGPD). Routes : GET `/profile`, POST `/profile/avatar`, POST `/profile/avatar/delete`, POST `/profile/preferences`
- **Service `AuthentikAvatarFetcher`** (`App\Application\Service\Avatar`) : télécharge le claim `picture` du userinfo OIDC et le cache localement via `AttachmentStorageInterface`. Bornes : timeout 5 s, taille max 2 Mo (vérifiée en streaming), content-type `image/*`, redimensionnement WebP 512×512 GD. Re-fetch déclenché si URL change OU TTL > 24 h. **Échec silencieux** loggé en `warning` (jamais de cassure du login). Branché dans `OidcUserProvider::ensureUserExists` après réconciliation. 8 tests unitaires (URL nulle/vide/cache frais/périmé/changée, HTTP non-200, content-type invalide, taille dépassée)
- **Interface `AttachmentStorageInterface`** + implémentation `LocalAttachmentStorage` (filesystem dans `var/uploads/`, URL publique `/uploads/{path}`, sécurité anti-path-traversal). Anticipation pour la GED externe (cf. specs §6). `UserAvatarResolver` utilise désormais `$storage->publicUrl()` au lieu d'un préfixe hardcodé
- **Extension PHP `gd`** ajoutée au `Dockerfile` (requise par `AuthentikAvatarFetcher` et `ProfileController` pour le redimensionnement WebP)
- **Logout SSO Authentik** (cf. specs §5.3) : `enable_end_session_listener: true` sur le firewall OIDC drenso. Le `LogoutEvent` Symfony déclenche automatiquement la redirection vers `end_session_endpoint` Authentik avec `id_token_hint` et `post_logout_redirect_uri = logout.target` (par défaut `/`). Le user est déconnecté à la fois côté app ET côté IdP. `docs/authentik.md` §1.1 et §2.4 mis à jour pour documenter le pré-requis "Post-logout Redirect URIs" côté provider Authentik
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
- **Service `UserAvatarResolver`** (`App\Application\Service\Avatar`) qui implémente la cascade définie en specs §3.8 : upload local → cache Authentik → Gravatar → initiales SVG. Préférence utilisateur `avatarSource` peut forcer une source. Fallback automatique sur les initiales si la source forcée n'est pas dispo (jamais d'avatar cassé)
- **DTO `AvatarRender`** : URL distante OU SVG inline + alt + size, consommé par le filtre Twig
- **Filtre Twig `user|avatar(size=64, class='')`** (`App\Twig\AvatarExtension`) qui rend `<img>` (URL) ou `<span><svg>...</svg></span>` (initiales) avec classes Tailwind par défaut (`rounded-full object-cover`), `loading="lazy"`, `decoding="async"` et bonnes pratiques a11y (`alt`, `role="img"`, `aria-label`)
- Génération Gravatar : hash SHA-256 (nouvelle API, MD5 dépréciée), `?d=identicon` pour avoir toujours une image (pattern unique par e-mail) plutôt qu'un 404 cassé
- Génération initiales SVG : 2 lettres max du `displayName`, fond couleur stable dérivé de `authentikId` (`crc32` modulo palette de 12 couleurs Tailwind)
- Tests unitaires `UserAvatarResolverTest` (11 tests, couvrant chaque branche de la cascade, déterminisme du hash Gravatar, déterminisme de la couleur initiale)
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

- **Callback OIDC `/login_check`** : route déclarée explicitement dans `SecurityController::loginCheck()` (méthode `never` interceptée par l'authenticator drenso). Sans cette route, le routeur Symfony rejetait le callback Authentik avec `No route found for "GET /login_check"` avant que le firewall OIDC n'ait l'occasion d'intercepter. Le `redirect_route` du firewall garde sa valeur par défaut (`login_check`)
- **Scopes OIDC effectivement transmis à Authentik** : `OIDC_SCOPES` était présent dans `.env` mais jamais lu — drenso retombait sur son scope par défaut (`openid` seul), ce qui faisait que le userinfo ne renvoyait que `sub`/`nonce` (pas d'`email`, `name`, `groups`). Nouveau paramètre `app.oidc_scopes` câblé sur l'env var, injecté dans `SecurityController` et passé à `OidcClient::generateAuthorizationRedirect(scopes: ...)` (string format OAuth2 `"openid email profile groups"` splittée sur les espaces, fallback `['openid']` si vide)
- **Log de rejet du filtrage groupes** : `OidcAccessGuard` log désormais en `warning` (canal Monolog standard) le rejet d'un utilisateur avec `authentik_id`, `user_groups` reçus du userinfo, et `required_groups` configurés — facilite le diagnostic d'une mauvaise config Authentik (Property Mapping `groups` manquant) ou d'un mismatch de nom de groupe
- **`/profile` : `Array to string conversion` sur l'affichage des flash messages** : `templates/profile/show.html.twig` itérait `{% for flash in app.flashes(['success', 'error']) %}` en supposant que `flash` était un message string, alors que `app.flashes()` retourne `{type: [messages]}` (donc `flash` était un tableau). Boucle imbriquée `{% for type, messages in ... %}{% for message in messages %}` et couleur dérivée du type plutôt que de `loop.index0` (l'ordre des types n'est pas garanti)
- `templates/home/index.html.twig` : marqueurs de conflit git non résolus (`<<<<<<<`, `=======`, `>>>>>>>`) laissés par le merge auto de la PR #17 sur le main contenant déjà la PR #16. Contenu consolidé : démos Stimulus + Live Component (apport #16) sans le `<main>` (qui vient désormais du layout via `base.html.twig`, apport #17)
- `App\Domain\User::getUserIdentifier()` : déclare `@return non-empty-string` + assertion runtime — répond à PHPStan level 9 sur la signature requise par `UserInterface`
- `tests/bootstrap.php` : suppression du `method_exists(Dotenv::class, 'bootEnv')` (toujours vrai depuis Symfony 5.1)
- Conversion des `{% include %}` en `{{ include() }}` dans `templates/base.html.twig` et `templates/_partials/_header.html.twig` (preset Symfony de Twig CS Fixer)

### Changed

- **Cadrage Lot 0 enrichi** (`docs/lots/lot-0-cadrage.md`) : statut explicité (vagues 1-2 livrées · vague 3 à attaquer · vague 4 à consolider · clôture en attente), notes ✅ ajoutées sur les vagues 1 et 2 (avec PR de référence), encart ⚠️ "à consolider" sur la vague 4 (PR #34/#35 poussées mais non validées), nouvelle **vague 5 — Clôture v0.1.0** détaillant pré-tag (audit vague 4, démos PO, validation `docs/local-dev.md`, déplacement `[Unreleased]` → `[0.1.0]`), tag (annoté `v0.1.0`, déclenchement `release.yml`, vérification GHCR + GitHub Release) et post-tag (statuts `✅ livré` dans cadrage et roadmap). §6 (plan d'attaque côté issues) complété avec sous-section "Consolidation vague 4" et liste vague 5
- **`/profile` : préférences en formulaires séparés** : la section Préférences avait un seul `<form>` couvrant à la fois la source d'avatar et la préférence Gravatar, avec un bouton « Enregistrer » distant du select. Split en deux formulaires indépendants, chacun avec son propre submit inline. Côté contrôleur, route unique `POST /profile/preferences` éclatée en `POST /profile/preferences/avatar-source` (`preferences_avatar_source`) et `POST /profile/preferences/gravatar` (`preferences_gravatar`) — chaque préférence est désormais enregistrable indépendamment, et le flash message reflète précisément ce qui a changé
- **Logout SSO Authentik désactivé (revert partiel #27)** : `enable_end_session_listener: false` sur le firewall OIDC drenso + suppression de `use_logout_target_path`. La redirection vers l'`end_session_endpoint` Authentik était bloquée côté navigateur par CORS — Turbo intercepte le POST `/logout` en fetch, et la 302 cross-origin vers le domaine Authentik ne peut pas être suivie. Le logout reste local-only : la session Symfony est invalidée mais la session SSO Authentik reste active. Pour réactiver le logout SSO, ajouter `data-turbo="false"` sur le `<form action="/logout">` ET remettre `enable_end_session_listener: true`
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
