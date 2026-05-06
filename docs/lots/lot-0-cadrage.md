# Lot 0 — Fondations · Cadrage détaillé

> Lot rattaché : **`v0.1.0`** · Statut : **🚧 en cours**
>
> Cadrage opérationnel du Lot 0. Reprend et détaille les items de [`docs/roadmap.md`](../roadmap.md) §"Lot 0", en les regroupant en vagues (sprints internes) avec critères d'acceptation et liste des issues à ouvrir.
>
> Source de vérité **fonctionnelle** : [`docs/specifications.md`](../specifications.md). En cas de divergence, les specs font foi.

## 1. Objectifs

À la fin du Lot 0 :

1. Un nouvel arrivant clone le repo, lance `make install`, voit l'app servie en HTTPS sur `https://spm.localhost`.
2. Il se connecte via Authentik, voit ses groupes Authentik affichés sur `/profile`, son rôle (`ROLE_ADMIN` si membre de `admin_spm`, `ROLE_USER` sinon).
3. Un admin accède à `/admin`, voit la liste des utilisateurs synchronisés depuis Authentik, peut consulter leur fiche.
4. Les événements de sécurité (`security.login.success`, `security.access_denied`, etc.) sont émis dans le bus Symfony EventDispatcher (vérifiable via `bin/console debug:event-dispatcher`). Pas de persistance encore (Lot 2).
5. L'application est utilisable confortablement sur smartphone (mobile-first).
6. Sentry capture les exceptions et les release sont taguées `APP_VERSION`.
7. La CI est verte (lint + tests + phpstan + composer audit + deptrac).
8. Une image taguée `v0.1.0` est publiée sur GHCR.

**Hors scope du Lot 0** (vient au Lot 1) :

- Toute notion de `Project`, `Task`, `Comment`, `Attachment`, `WorkingGroup` (synchro auto au login `incluse` au Lot 1, pas Lot 0)
- Les voters dynamiques `ROLE_CHEF_PROJET` / `ROLE_ACTEUR` / `ROLE_LECTEUR` (calcul dynamique = Lot 1)

## 2. Choix techniques précis

| Composant | Version / lib | Justification |
|---|---|---|
| PHP | **8.4** | Decision specs §5.1 |
| Symfony | **7.x** | Decision specs §5.1 |
| FrankenPHP | **dernière stable** | Mode worker en prod, classique en dev |
| Postgres | **16-alpine** | Cohérent prod/dev |
| Redis | **7-alpine** | Cache + sessions + Messenger |
| OIDC | **`drenso/symfony-oidc-bundle`** | Décision authentik.md §2.2 (à confirmer en pratique) |
| CSS | **Tailwind via `symfony/ux-twig-component`** | Decision specs §8.2 |
| Tests | **PHPUnit 11+** | |
| Static analysis | **PHPStan level 9** | qualite.md §1 |
| Code style | **PHP-CS-Fixer (preset Symfony + PHP84Migration)** | qualite.md §1 |
| Hooks pre-commit | **GrumPHP** | qualite.md §6 |
| Monitoring | **Sentry (`sentry/sentry-symfony`)** | qualite.md §10, branché Lot 0 |
| CI | **GitHub Actions** + miroir GitLab | roadmap |
| Image registry | **GHCR** | roadmap |

## 3. Découpage en vagues

Quatre vagues séquentielles. Chaque vague est cohérente fonctionnellement et fait l'objet de plusieurs PR (cf. §6).

### Vague 1 — Squelette technique (semaine 1)

Objectif : un `make install` qui démarre la stack dev en HTTPS.

- [ ] Initialiser `composer create-project symfony/skeleton`
- [ ] Structure `src/` : `Controller/`, `Domain/`, `Application/`, `Infrastructure/`, `Security/`, `Twig/` (cf. specs §5.2)
- [ ] `Dockerfile` multi-stages (base, dev avec Xdebug, prod)
- [ ] `docker-compose.dev.yml` (FrankenPHP en HTTPS sur spm.localhost, Postgres, Redis, Mailpit) — finaliser depuis `docker-compose.dev.yml.example`
- [ ] `docker-compose.prod.yml` (cf. `docs/deploiement.md` §3, avec service `migrate` one-shot)
- [ ] Réseaux Docker `internal_net` + `caddy_net` (cf. specs §5.3)
- [ ] Configuration Doctrine + Postgres + extension `uuid-ossp` ou `pgcrypto`
- [ ] Configuration Redis (cache, sessions, transport Messenger)
- [ ] `Makefile` : `install`, `migrate`, `migration`, `fixtures`, `reset`, `shell`, `logs`, `test`, `stan`, `cs`, `cs-check`, `rector`, `twig-cs`, `deptrac`, `audit`, `qa`, `stop`, `down`, `clean`
- [ ] Layout Twig responsive (header avec menu user, burger menu < 1024 px, footer, sidebar admin)
- [ ] Symfony UX Turbo + Stimulus + Live Components, composant Hello World
- [ ] Tailwind via `symfony/ux-twig-component` + processus de build (esbuild via AssetMapper ou Webpack Encore — à trancher en début de Lot 0)
- [ ] `.editorconfig`, `.gitattributes`

**Critère de fin de vague** : `make install` puis `make up` → page d'accueil affichée sur `https://spm.localhost` avec le composant Hello World fonctionnel (Turbo + Stimulus).

### Vague 2 — Authentification OIDC + projection User (semaine 2)

Objectif : un user peut se connecter via Authentik et voir son profil.

- [ ] Bundle OIDC choisi (drenso/symfony-oidc-bundle ou alternative) installé et configuré
- [ ] Variables d'environnement : `OIDC_ISSUER_URL`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `OIDC_REDIRECT_URI`, `OIDC_SCOPES`, `OIDC_ADMIN_GROUP`, `OIDC_REQUIRED_GROUPS`
- [ ] Entité `User` (cf. modele-de-donnees.md §3.8 — tous les champs avatar inclus)
- [ ] Sequencer Doctrine pour le PK `Uuid v7` (Symfony Uid)
- [ ] Migration initiale `users`
- [ ] `OidcUserProvider` qui réconcilie par `authentikId`, met à jour les champs au login, attribue `ROLE_ADMIN` si membre de `OIDC_ADMIN_GROUP`
- [ ] **Filtrage `OIDC_REQUIRED_GROUPS`** : rejet du login avec page "Accès non autorisé" + événement `security.access_denied` si l'utilisateur n'est dans aucun des groupes requis
- [ ] Routes `/login`, `/oidc/callback`, `/logout` (avec logout SSO côté Authentik)
- [ ] Page `/profile` : affiche `displayName`, `email`, `username`, groupes Authentik (`groupsSnapshot`), rôles, lien direct vers la fiche Authentik
- [ ] **Service `UserAvatarResolver`** + filtre Twig `{{ user|avatar(size) }}` : priorité upload local → Authentik (cache) → Gravatar → initiales SVG (cf. specs §3.8)
- [ ] **Service `AuthentikAvatarFetcher`** : téléchargement borné (timeout 5 s, taille 2 Mo, content-type image/*), redimensionnement 512×512, stockage via `AttachmentStorage`, déclenché au login si TTL > 24 h (cf. specs §3.8)
- [ ] Page `/profile` : upload d'avatar local, toggle `gravatarAllowed`, sélecteur `avatarSource`
- [ ] Sessions Redis avec TTL aligné sur la durée de vie du token Authentik

**Critère de fin de vague** : un user peut se connecter via Authentik, voir son profil avec son avatar (cascade priorité), un user non membre de `OIDC_REQUIRED_GROUPS` est rejeté avec un message clair.

### Vague 3 — Section administration de base (semaine 3)

Objectif : un admin gère les liens externes et consulte les utilisateurs.

- [ ] Layout admin distinct (sidebar, breadcrumb, badge "Admin")
- [ ] Voter de base : `ROLE_ADMIN` requis pour `/admin/*`
- [ ] Liste des utilisateurs : nom, email, groupes Authentik, rôles dérivés, dernière connexion, statut actif/désactivé, lien vers la fiche Authentik
- [ ] Tri et filtres (par rôle, par groupe Authentik, recherche full-text sur nom/email)
- [ ] Détail utilisateur : historique de connexions, espace réservé pour les contributions futures
- [ ] **Gestion des liens externes** (entité `ExternalLink`) : CRUD admin (libellé, URL, icône, description, position, actif/inactif). Pas de restriction par rôle (cf. specs §3.12)
- [ ] **Composant Twig "lanceur d'apps"** dans le header (icône grille → dropdown desktop / panneau plein-écran mobile)
- [ ] Lecture des `ExternalLink` actifs (visibles par tout utilisateur authentifié)
- [ ] Cible `_blank` + `rel="noopener noreferrer"`

**Critère de fin de vague** : un admin voit la liste des users, peut CRUD les liens externes ; un user non-admin voit le menu d'outils dans le header.

### Vague 4 — Infrastructure d'audit (sans stockage) + outillage qualité + CI (semaine 3-4)

Objectif : pipeline qualité opérationnel + classes d'événements applicatifs émises (sans persistance, vient au Lot 2).

#### Audit log (préparation)

- [ ] Interface `AuditableEvent` dans `Application/Event/`
- [ ] Classes pour la catégorie `security` : `UserLoggedIn`, `UserLoggedOut`, `LoginFailed`, `AccessDenied`, `SessionExpired`
- [ ] Classes pour la catégorie `user` : `UserFirstSeen`, `UserProfileUpdated`, `UserDisabled`
- [ ] Dispatch via Symfony EventDispatcher dans le code de sécurité OIDC
- [ ] Subscriber `dev` qui log dans la console (pour vérification)

#### Outillage qualité

- [ ] `phpstan.neon.dist` (level 9 + extensions Symfony, Doctrine)
- [ ] `phpunit.dist.xml` + base de test `spm_test` recréée à chaque run
- [ ] `.php-cs-fixer.dist.php` (preset Symfony + PHP84Migration)
- [ ] `.twig-cs-fixer.dist.php`
- [ ] `rector.php` (sets Symfony, Doctrine, PHP 8.4)
- [ ] `deptrac.yaml` (couches Controller / Application / Domain / Infrastructure)
- [ ] `grumphp.yml` avec hooks pre-commit (phpcsfixer, twigcsfixer, twig lint, yamllint, composer) et pre-push (phpstan, securitychecker)
- [ ] Hook git auto-installé via `composer install`

#### Sentry

- [ ] Bundle `sentry/sentry-symfony` installé
- [ ] `config/packages/sentry.yaml` selon `docs/qualite.md` §10.1
- [ ] Variables `SENTRY_DSN`, `SENTRY_ENV`, `APP_VERSION`
- [ ] Filtrage `NotFoundHttpException`, `AccessDeniedException` (logs locaux uniquement)
- [ ] Test manuel : forcer une exception en dev, vérifier qu'elle apparaît dans Sentry

#### CI

- [ ] Workflow `.github/workflows/ci.yml` : 3 jobs en parallèle (`lint`, `test`, `audit`)
  - `lint` : composer install + php-cs-fixer --dry-run + twig-cs-fixer --dry-run + phpstan + lint:twig + lint:yaml + lint:container + deptrac
  - `test` : services postgres + redis + composer install + doctrine:schema:validate + doctrine:migrations:migrate + phpunit
  - `audit` : composer audit + rector --dry-run
- [ ] Workflow `.github/workflows/release.yml` : sur tag `v*.*.*`, build multi-arch, push GHCR avec tags `v0.1.0` + `latest`, création GitHub Release avec changelog
- [ ] Miroir GitLab CI (`.gitlab-ci.yml`) si tu en as besoin (sinon repousser à plus tard)

**Critère de fin de vague** : push d'une PR → CI verte en < 5 min ; tag `v0.1.0` → image disponible sur GHCR ; exception forcée → ticket Sentry avec release `v0.1.0`.

## 4. Conventions du Lot 0

Rappel des conventions globales (cf. [`docs/workflow.md`](../workflow.md)) appliquées à ce lot :

- **Branches** : `feature/<n°>-<slug>`, `fix/<n°>-<slug>`, etc. avec n° de l'issue
- **Commits** : Conventional Commits en français (`feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`, `ci:`)
- **PR** : titre `<type>: <résumé> (#<n°>)`, body `Closes #<n°>` + résumé. Draft tant qu'en cours, **squash merge** à la fin.
- **Une PR = une fonctionnalité testable** (pas de PR géante mêlant plusieurs sujets)
- **CI verte obligatoire** avant merge
- **Sentry release tracking** activé dès qu'on a la première PR mergée

## 5. Décisions techniques tranchées (validées par le PO)

| # | Sujet | Décision |
|---|---|---|
| 1 | Build front | **AssetMapper** (Symfony natif, pas de Node en build, parfait pour Tailwind via le plugin officiel) |
| 2 | Bundle OIDC | **`drenso/symfony-oidc-bundle`**. Fallback prévu sur `KnpUOAuth2ClientBundle` + custom resource owner si on rencontre une limitation (logout SSO complexe par exemple) |
| 3 | Mode stub `OIDC_DRIVER=fake` | **Non en v1**. Configurer un provider dev sur l'Authentik existant. Reconsidérer si demande explicite |
| 4 | Worker Messenger | **Un seul service `worker`** en v1. Scaler plus tard si besoin |
| 5 | Nom de l'application dans Sentry | **`spm`** (cohérent avec la nomenclature du reste — DB, Caddy, etc.) |
| 6 | Exposition Mailpit | **`http://localhost:8025`** (HTTP direct, pas de TLS). Pas de domaine HTTPS pour Mailpit |

## 6. Plan d'attaque côté issues

À ouvrir une fois ce cadrage validé. Convention : `#<n°>` = ID GitHub auto-attribué.

### Vague 1 — Squelette technique

- [ ] `feat: bootstrap composer + structure src/`
- [ ] `feat: dockerfile multi-stages + compose dev avec TLS local`
- [ ] `feat: compose prod avec service migrate + networks isolés`
- [ ] `feat: layout twig responsive mobile-first`
- [ ] `feat: tailwind + symfony ux turbo/stimulus + composant hello world`
- [ ] `feat: makefile (install, test, stan, cs, qa, etc.)`
- [ ] `chore: editorconfig, gitattributes`

### Vague 2 — Authentification OIDC

- [ ] `feat: entité User + migration initiale`
- [ ] `feat: configuration drenso/symfony-oidc-bundle`
- [ ] `feat: filtrage OIDC_REQUIRED_GROUPS au callback`
- [ ] `feat: page /profile + filtre twig avatar`
- [ ] `feat: service UserAvatarResolver (cascade upload/authentik/gravatar/initials)`
- [ ] `feat: service AuthentikAvatarFetcher (cache local 24h)`
- [ ] `feat: upload avatar local depuis /profile`
- [ ] `feat: logout local + sso authentik`

### Vague 3 — Administration

- [ ] `feat: layout admin + voter ROLE_ADMIN sur /admin/*`
- [ ] `feat: liste utilisateurs avec tri/filtres/recherche`
- [ ] `feat: fiche utilisateur`
- [ ] `feat: entité ExternalLink + CRUD admin`
- [ ] `feat: composant lanceur d'apps dans le header`

### Vague 4 — Audit + qualité + CI

- [ ] `feat: classes AuditableEvent + dispatch dans le flow OIDC`
- [ ] `chore: phpstan/php-cs-fixer/twig-cs-fixer/rector/deptrac/phpunit configuration`
- [ ] `chore: grumphp avec hooks pre-commit/pre-push`
- [ ] `feat: integration sentry-symfony + variables d'env`
- [ ] `ci: workflow github actions lint/test/audit`
- [ ] `ci: workflow github actions release (build + push ghcr + github release)`
- [ ] `ci: miroir gitlab` *(optionnel selon ta priorité)*

## 7. Critère de clôture du Lot 0

- [ ] Toutes les issues mergées sur `main`
- [ ] CI verte sur `main`
- [ ] Image `v0.1.0` publiée sur GHCR
- [ ] Tag `v0.1.0` créé avec changelog
- [ ] GitHub Release `v0.1.0` publiée
- [ ] Doc à jour (notamment `docs/local-dev.md` validée par un nouveau dev qui clone et lance)
- [ ] PO valide les démos (login OIDC, profil, admin, lanceur d'apps)
