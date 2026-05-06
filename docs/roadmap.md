# Roadmap

> Document vivant. Idées non priorisées en bas, regroupées en lots dans la section principale. Chaque lot livré donnera lieu à un **tag git annoté** (`v0.X.0`).

## Convention

- Un **lot** = un ensemble cohérent de tâches livrables ensemble = **un tag** sur `main`.
- Une **tâche** = une issue GitHub = une branche `feature/<n°>-<slug>` (ou `fix/`, `chore/`, `docs/`) = une PR.
- Statut des lots : `📅 prévu` / `🚧 en cours` / `✅ livré`.

## Lots planifiés

### Lot 0 — Fondations · `v0.1.0` · 📅 prévu

Squelette technique opérationnel + section administration de base + infrastructure d'audit log. Pas encore de gestion de projets/tâches.

**Squelette technique**

- [ ] `composer create-project symfony/skeleton` + structure `src/` (Controller / Application / Domain / Infrastructure / Security)
- [ ] Dockerfile FrankenPHP + `docker-compose.dev.yml` + `docker-compose.prod.yml`
- [ ] **TLS local en dev** : FrankenPHP/Caddy sert sur `https://spm.localhost` (variable `DEV_SERVER_NAME` surchargeable), CA Caddy persistée dans le volume `caddy_data`, doc d'install dans le trust store (cf. `docs/local-dev.md` §3)
- [ ] `Makefile` (install, migrate, test, stan, cs, shell, reset)
- [ ] Configuration Doctrine + Postgres + premières migrations
- [ ] Configuration Redis (cache + sessions + Messenger)
- [ ] Layout Twig responsive (mobile-first, burger menu < 1024 px, header avec menu utilisateur, footer)
- [ ] Symfony UX Turbo + Stimulus en place + composant Hello World
- [ ] Choix du framework CSS (Tailwind vs Bootstrap) tranché et intégré
- [ ] CI GitHub Actions (lint + tests + phpstan + composer audit + deptrac)
- [ ] CI GitLab miroir
- [ ] Build + push image GHCR sur tag
- [ ] Service `migrate` one-shot dans `docker-compose.prod.yml` (cf. `docs/deploiement.md` §6.1)
- [ ] Réseaux Docker séparés `internal_net` (services) et `caddy_net` (proxy externe)
- [ ] **GrumPHP** installé et hooks pre-commit/pre-push configurés (cf. `docs/qualite.md` §6)
- [ ] **Sentry** branché (`sentry/sentry-symfony`, DSN via env, release = `APP_VERSION`, cf. `docs/qualite.md` §10)
- [ ] Doc d'install à jour (`docs/local-dev.md`, `docs/deploiement.md`, `docs/authentik.md`)

**Authentification**

- [ ] Intégration Authentik OIDC (bundle `drenso/symfony-oidc-bundle` à confirmer)
- [ ] Attribution de `ROLE_ADMIN` au login si l'utilisateur est dans le groupe Authentik défini par `OIDC_ADMIN_GROUP` (défaut `admin_spm`). Les autres rôles (`ROLE_CHEF_PROJET`, `ROLE_ACTEUR`, `ROLE_LECTEUR`) sont calculés dynamiquement par les voters (cf. specs §2)
- [ ] **Filtrage d'accès par méga-groupe** : variable `OIDC_REQUIRED_GROUPS` (liste séparée par virgules). Au callback OIDC, l'app vérifie qu'au moins un groupe Authentik de l'utilisateur figure dans cette liste. Sinon, rejet avec page "Accès non autorisé" claire et événement audit `security.access_denied`. Defense in depth combiné avec la Policy Binding côté Authentik
- [ ] Entité `User` (projection locale d'Authentik : `authentikId`, `username`, `email`, `displayName`, `roles`, `groupsSnapshot`, `lastLoginAt`, `disabledAt`, `avatarPath`, `authentikAvatarSourceUrl`, `authentikAvatarPath`, `authentikAvatarFetchedAt`, `avatarSource`, `gravatarAllowed`)
- [ ] Réconciliation utilisateur au login (création si nouveau, mise à jour sinon, capture du claim `picture` Authentik)
- [ ] Service `AuthentikAvatarFetcher` : téléchargement borné (timeout 5 s, taille 2 Mo, content-type `image/*`), redimensionnement 512×512, stockage via `AttachmentStorage`, déclenché au login si URL source change ou TTL dépassé (24 h). Échec silencieux → fallback sur source suivante
- [ ] Service `UserAvatarResolver` (priorité upload local → Authentik (cache) → Gravatar → initiales SVG, cf. specs §3.8)
- [ ] Filtre Twig `user|avatar(size)` qui encapsule le résolveur
- [ ] Page `/profile` : groupes Authentik affichés, lien vers Authentik, **upload d'avatar local** (jpg/png/webp, 2 Mo, resize 512×512), toggle "fallback Gravatar autorisé", sélecteur `avatarSource` (auto / local / authentik / gravatar / initials)
- [ ] Logout local + logout SSO côté Authentik
- [ ] Voter de base + handlers `AccessDeniedException`

**Section administration (réservée `ROLE_ADMIN`)**

- [ ] Layout admin distinct (sidebar de navigation admin)
- [ ] Liste des utilisateurs : nom, e-mail, groupes, rôles dérivés, dernière connexion, statut (actif/désactivé), lien direct vers la fiche Authentik
- [ ] Tri et filtres sur la liste utilisateurs (par rôle, par groupe, recherche par nom/email)
- [ ] Détail utilisateur : historique de connexions, contributions à venir (sera enrichi plus tard)
- [ ] Pas de création/édition d'utilisateur dans l'app (c'est Authentik qui gère)
- [ ] **Gestion des liens externes** (CRUD `ExternalLink` : libellé, URL, icône, description, position, actif/inactif)

**Menu d'outils externes (front)**

- [ ] Composant Twig "lanceur d'apps" intégré dans le header (icône grille → dropdown)
- [ ] Lecture des `ExternalLink` actifs (visible par tout utilisateur authentifié)
- [ ] Vue mobile adaptée (panneau plein écran plutôt que dropdown)
- [ ] Cible `_blank` + `rel="noopener noreferrer"` pour la sécurité

**Préparation de l'audit log (sans stockage encore)**

> Le stockage et l'UI viennent au **Lot 2** (lot dédié). Mais on définit dès maintenant les classes d'événements applicatifs et on les émet depuis le code de sécurité, pour ne pas avoir à revenir sur ce code plus tard.

- [ ] Définition des classes d'événements applicatifs côté `Application/Event/` (`UserLoggedIn`, `UserLoggedOut`, `LoginFailed`, `AccessDenied`, voir liste complète dans `docs/specifications.md` §3.9)
- [ ] Dispatch via Symfony EventDispatcher dans le flux de sécurité OIDC
- [ ] Pas de subscriber persistant à ce stade (ou un subscriber `dev` qui log dans la console)

**Critère de fin** : un nouvel arrivant clone le repo, lance `make install`, se connecte via Authentik, voit ses groupes sur `/profile`. Un admin accède à `/admin`, voit la liste des utilisateurs. Les événements de sécurité sont émis dans Symfony (vérifiable via `bin/console debug:event-dispatcher`). L'application est utilisable confortablement sur smartphone. La CI est verte. Une image taguée `v0.1.0` est publiée sur GHCR.

### Lot 1 — Projets, tâches, groupes de travail et demandeurs · `v0.2.0` · 📅 prévu

Cœur métier : CRUD de base avec assignation, statuts, groupes de travail et gestion des demandeurs externes. Voir `docs/specifications.md` §3.1 (Project), §3.2 (Task), §3.10 (Requester), §3.11 (WorkingGroup) pour les attributs et cycles de vie détaillés.

**Groupes de travail** (à faire en premier — Project/Task en dépendent)

- [ ] Entité `AuthentikGroup` (cache local des groupes Authentik) + entité `WorkingGroup` (slug, name, description, color, icon, authentikGroup FK, position, archivedAt) + migrations + fixtures
- [ ] **Service de synchro Authentik** : `AuthentikGroupSynchronizer` qui appelle l'API admin Authentik (scope `read:group`), met à jour la table `authentik_groups`, marque les disparus avec `vanishedAt`. Cron horaire + bouton manuel "Actualiser" dans l'admin
- [ ] Écran admin "Groupes Authentik" : liste paginée avec checkbox **`Visible`** par ligne (défaut décoché), boutons "Créer un groupe de travail" / "Modifier le mapping"
- [ ] CRUD WorkingGroup en admin (liste, fiche, créer, éditer, archiver) — la sélection du groupe Authentik se fait depuis la liste des `AuthentikGroup` `visible`, plus de saisie libre
- [ ] Calcul des groupes de travail de l'utilisateur au login (jointure `authentik_groups.authentikId ∈ user.authentikGroups`), mis en cache Redis
- [ ] Méthode `User::getWorkingGroups()` exposée à Twig
- [ ] Page de liste publique des groupes de travail actifs `/groupes-de-travail`
- [ ] Vue détaillée `/groupes-de-travail/<slug>` (sera enrichie avec projets/tâches une fois Project/Task disponibles)
- [ ] Indicateur "mapping orphelin" si l'`AuthentikGroup` lié est marqué `vanishedAt`
- [ ] Avertissement admin si plusieurs WorkingGroup pointent vers le même `AuthentikGroup`
- [ ] **Voters Project / Task** qui calculent à la volée `ROLE_CHEF_PROJET` (owner/coOwner/createdBy), `ROLE_ACTEUR` (membre d'un WG associé), `ROLE_LECTEUR` (sinon, si visible)

**Projets**

- [ ] Entité `Project` complète selon §3.1 (reference généré, slug, title, summary, description markdown, status, visibility, restrictedToGroups, owner, coOwners, category, labels, workingGroups, dates, archivedAt)
- [ ] Workflow Symfony pour le cycle de vie (5 statuts, transitions définies en §3.1)
- [ ] CRUD Project (liste paginée, fiche, créer, éditer, archiver/désarchiver)
- [ ] Filtres liste : statut, visibilité, owner, groupe de travail, category, archived ou non
- [ ] Recherche par référence (`#P-2026-014`) ou texte
- [ ] Voters Project : owner / coOwner / restricted visibility
- [ ] Transfert d'ownership (modale dédiée + audit)
- [ ] Duplication d'un projet (copie en `brouillon` sans tâches)
- [ ] Génération de la référence `P-YYYY-NNN` (incrémentale annuelle, séquence Postgres `project_reference_seq_<year>`, cf. specs §8.14)
- [ ] Toggle `restrictedToWorkingGroups` sur Project (visibilité réservée aux membres des groupes de travail associés, cf. specs §3.1)

**Tâches**

- [ ] Entité `Task` complète selon §3.2 (reference, title, description, status, priority, **project (nullable)**, **parentTask (nullable, sous-tâches)**, **visibility + restrictedToGroups (si autonome)**, assignee, requester, workingGroups hérités OU saisis manuellement, labels, dueDate, estimatedEffort, blockedReason, publicLabel, **source**, lastStatusChangeAt)
- [ ] Workflow Symfony pour le cycle de vie (6 statuts, transitions et garde-fous définis en §3.2)
- [ ] CRUD Task (liste filtrée par projet, fiche, créer, éditer)
- [ ] **Création de tâche autonome** (sans projet) : formulaire dédié avec champ `visibility`, saisie manuelle des `workingGroups`, source = `manual`
- [ ] **Vue "Tâches autonomes"** `/taches/autonomes` (filtre `project=null AND parentTask=null`)
- [ ] **Sous-tâches** : champ `parentTask`, validateur anti-cycle + profondeur max 3, breadcrumb sur fiche tâche, onglet "Sous-tâches" listant les enfants avec compteur `X/Y terminées`, cascade `annulee` sur sous-tâches non terminales si parente annulée, avertissement (non bloquant) à la clôture parente avec sous-tâches non terminales (cf. specs §3.2 sous-section "Sous-tâches")
- [ ] **Rattachement à un projet** (action sur tâche autonome) et **détachement** (rendre une tâche autonome) avec audit
- [ ] Champ "motif" obligatoire pour les transitions vers `bloquee` et `annulee`
- [ ] Cascade : annulation automatique des tâches d'un projet `annule` (uniquement si la tâche a un projet)
- [ ] Blocage des transitions de tâches si projet en `en_pause`/`termine`/`annule` (uniquement si la tâche a un projet)
- [ ] Génération de la référence `T-YYYY-NNN` (séquence Postgres `task_reference_seq_<year>`, distincte de celle de Project — cf. décision §8.14)
- [ ] Vue "Mes tâches" (assigné = moi) avec onglets par statut
- [ ] Voters Task : assignée à moi / créateur (autonome) / projet dont je suis owner / admin
- [ ] Surcharge des groupes de travail au niveau de la tâche (par défaut hérités du projet quand projet présent)

**Demandeurs (Requester)**

- [ ] Entité `Requester` (firstName, lastName, email, phone, address, notes, consentNotifications, consentDate, consentWithdrawnAt) + migration + fixtures
- [ ] Validation : nom + prénom obligatoires, au moins email **ou** téléphone obligatoire
- [ ] CRUD Requester en section admin/agent (liste, fiche, créer, éditer)
- [ ] Recherche / autocomplete sur les demandeurs existants depuis le formulaire de tâche
- [ ] Association optionnelle d'un demandeur à une tâche (relation many-to-one nullable)
- [ ] Fiche demandeur : liste des tâches associées
- [ ] Anonymisation au lieu de suppression dure
- [ ] Pas encore de notifications ni de portail (Lot 4 et 6 respectivement)

**Événements et tests**

- [ ] **Émission des événements applicatifs** : `working_group.*`, `project.*`, `task.*` (incluant `task.requester_linked/unlinked`, `task.cascade_cancelled`, `task.working_groups_changed`, `task.attached_to_project`, `task.detached_from_project`), `requester.created/updated/anonymized` (cf. `docs/specifications.md` §3.9)
- [ ] Tests fonctionnels du parcours complet (création groupe de travail → projet → tâche → association demandeur, cascade d'annulation, transitions bloquées par projet en pause, **création d'une tâche autonome puis rattachement à un projet**)
- [ ] Vues mobile testées (liste projets en cartes, formulaires adaptés, badges groupe de travail)

### Lot 2 — Audit log et journalisation · `v0.3.0` · 📅 prévu

Stockage et consultation de tous les événements applicatifs émis depuis le Lot 0. Ce lot ne nécessite aucun changement dans le code des autres lots — les événements sont déjà dispatchés.

- [ ] Entité `AuditLog` immuable (id, occurredAt, category, action, actor, subjectType, subjectId, payload JSON, ip, userAgent)
- [ ] Service `AuditLogger` injectable (utilisable manuellement si besoin)
- [ ] EventSubscriber unique qui consomme **tous** les événements applicatifs définis et les persiste en `AuditLog`
- [ ] Migration créant la table `audit_log` avec index utiles (occurredAt, actor_id, category, subjectType+subjectId)
- [ ] Écran admin "Journal d'événements" (`/admin/audit`)
  - [ ] Liste paginée (50/page), tri par date desc
  - [ ] Filtres : catégorie, action, utilisateur, intervalle de dates, sujet (type + id)
  - [ ] Recherche texte dans `payload` (JSONB)
  - [ ] Export CSV de la sélection filtrée
  - [ ] Vue mobile en cartes empilées
- [ ] Page de détail d'un événement (payload formaté lisiblement)
- [ ] Sur la fiche utilisateur (admin) : onglet "historique" filtrant le journal sur cet utilisateur
- [ ] Sur la fiche projet/tâche : encart "activité" filtrant le journal sur cette ressource
- [ ] Tests fonctionnels : login, création projet, modif tâche → vérifier qu'un `AuditLog` est créé avec les bons champs

**Critère de fin** : tous les événements émis depuis le Lot 0 sont consultables et filtrables dans `/admin/audit`. Une commande `app:audit:purge --before=2023-01-01` permet la purge manuelle (sans interface). Pas de perte d'événements antérieurs au déploiement de ce lot — ils n'auront simplement pas été enregistrés.

### Lot 3 — Vue d'ensemble · `v0.4.0` · 📅 prévu

Visualisation et recherche.

- [ ] Vue Kanban des tâches d'un projet (drag & drop via Stimulus + Turbo)
- [ ] Recherche full-text Postgres (titre, description) sur projets et tâches
- [ ] Filtres avancés (statut, assigné, échéance, étiquettes)
- [ ] Dashboard d'accueil (mes tâches en retard, projets que je suis, activité récente — données déjà dispo via l'audit log)
- [ ] Système d'étiquettes libres
- [ ] Catégories hiérarchiques administrées

### Lot 4 — Collaboration · `v0.5.0` · 📅 prévu

Échanges autour des projets et tâches, et premières notifications aux demandeurs.

**Échanges internes**

- [ ] Commentaires markdown sur Project et Task
- [ ] Champ "visible par le demandeur" sur les commentaires de Task (case à cocher, faux par défaut)
- [ ] Mentions `@utilisateur` (autocomplete)
- [ ] **Références croisées `#P-YYYY-NNN` / `#T-YYYY-NNN`** dans descriptions et commentaires (cf. specs §3.13) :
  - [ ] Service `CrossReferenceParser` (regex stricte, respect des blocs de code et liens existants)
  - [ ] Linkifier markdown → HTML avec tooltip (statut, titre, assignée) et classes CSS par statut
  - [ ] Entité `CrossReference` + subscriber Doctrine qui diff/insère au save de Project, Task, Comment
  - [ ] Bloc "Référencé dans" sur les fiches Project et Task (backlinks ordonnés par date)
  - [ ] Endpoint `GET /api/internal/references/search?q=...` (full-text Postgres, voters appliqués, rate limit 30/min)
  - [ ] Composant Stimulus d'autocomplete au caractère `#` dans les textareas markdown (style GitHub), insertion `#P-YYYY-NNN-slug` ou `#T-YYYY-NNN-slug` à la sélection
- [ ] Pièces jointes (upload, prévisualisation, suppression) — limites : **25 Mo / fichier, 10 fichiers max** par objet, types autorisés cf. specs §3.5
- [ ] Interface `AttachmentStorage` isolée (implémentation `FileSystemStorage` en v1) pour permettre une bascule future vers une GED externe sans toucher au métier
- [ ] Notifications in-app pour les agents (badge + dropdown)
- [ ] Notifications e-mail aux agents (Mailer + Messenger async)
- [ ] Préférences utilisateur (toggle e-mail / in-app)
- [ ] Système "suivre un projet/tâche"

**Notifications aux demandeurs externes**

- [ ] Envoi e-mail au demandeur lors d'un changement de statut significatif de la tâche (uniquement si `email` renseigné et `consentNotifications=true`)
- [ ] Modèle d'e-mail unique (HTML + texte), sobre, identité mairie, lien de désabonnement signé
- [ ] Page de désabonnement (révoque le consentement, log l'événement `requester.consent_withdrawn`)
- [ ] Indicateur sur la fiche tâche : "demandeur notifié le X" / "demandeur non joignable"

**Événements**

- [ ] Émission des événements applicatifs : `comment.created/edited/deleted`, `attachment.uploaded/deleted`, `notification.sent`, `requester.notification_sent`, `requester.consent_withdrawn`

### Lot 5 — Pilotage · `v0.6.0` · 📅 prévu

Outils de suivi macro.

- [ ] Jalons sur les projets + frise chronologique
- [ ] Vue calendrier (échéances + jalons) avec FullCalendar ou équivalent léger
- [ ] Export CSV des projets et tâches
- [ ] Export PDF d'une fiche projet (rapport complet)
- [ ] Tableau de bord "vue élu" (projets actifs, jalons à venir, alertes)
- [ ] Rappels d'échéance (J-3, J-1) par e-mail
- [ ] Émission des événements applicatifs : `milestone.created/updated/reached`, `export.generated`

### Lot 6 — Portail demandeur et préparation API citoyenne · `v0.7.0` · 📅 prévu

Ouverture maîtrisée vers l'extérieur : portail de suivi pour les demandeurs (jeton) et première brique API pour la future application citoyenne.

**Portail demandeur (jeton)**

- [ ] Génération d'un jeton aléatoire (32 octets, base62) à la création du Requester ; stockage hashé en base
- [ ] Route publique `/suivi/{jeton}` (rate-limited) listant les tâches du demandeur
- [ ] Vue mobile-first (le demandeur consulte souvent depuis son téléphone)
- [ ] Affichage des statuts en **libellés simplifiés mappés** : Reçu / En traitement / Traité / Sans suite (mapping fixe v1)
- [ ] Affichage des tâches : libellé statut, dates, historique des commentaires marqués "visible par le demandeur"
- [ ] **Formulaire de commentaire** depuis le portail (rate limit : 5 commentaires max / jour / jeton)
- [ ] **Pièces jointes du portail** : photos uniquement (jpg/png/heic/webp), 5 Mo max après compression, 3 fichiers par commentaire, scan ClamAV obligatoire, redimensionnement auto si dimension > 2048 px
- [ ] Notification "nouveau commentaire demandeur" envoyée à l'assignée et à l'owner du projet
- [ ] Bandeau RGPD/finalité sur la page
- [ ] Headers `Cache-Control: no-store` sur ces routes
- [ ] Expiration automatique du jeton 30 j après clôture de la dernière tâche
- [ ] Révocation manuelle par un agent (régénération possible)
- [ ] Modération a posteriori : un agent peut masquer un commentaire abusif
- [ ] Lien d'accès au portail injecté dans tous les e-mails de notification du Lot 4

**API citoyenne (préparation)**

- [ ] Mise en place d'API Platform sur les ressources publiques (Project en lecture)
- [ ] Authentification API par token (clés générées par les admins)
- [ ] Endpoint `POST /api/signalements` qui crée un Requester (ou réutilise par dédup e-mail) + une **tâche autonome** (sans projet, `source=citizen_api`)
- [ ] Documentation OpenAPI
- [ ] Tests d'intégration API
- [ ] Rate limiting (Symfony RateLimiter + Redis)
- [ ] Vue dédiée "Signalements citoyens à traiter" : filtre tâches autonomes avec `source=citizen_api` et statut `a_faire`/`en_cours`

**Événements**

- [ ] Émission des événements applicatifs : `requester.token_generated/revoked`, `requester.portal.viewed/commented`, `api.token.created/revoked`, `api.signalement.received`, `api.request.received` (volume — à filtrer)

### Lot 7+ — À définir

Voir la section "Backlog" ci-dessous.

## Backlog (idées non priorisées)

> Dépose ici tes idées au fil de l'eau. On les rangera dans des lots plus tard.

### Fonctionnel

- [ ] Templates de projets (réutilisables)
- [ ] Sous-tâches / dépendances entre tâches
- [ ] Votes / réactions sur les commentaires
- [ ] Vue Gantt simplifiée
- [ ] Export ICS du calendrier
- [ ] Imports depuis CSV (migration depuis tableurs existants)
- [ ] Intégration parapheur (signature)
- [ ] Webhooks sortants
- [ ] Recherche multi-critères sauvegardée
- [ ] Mode "présentation" en réunion (vue plein écran d'un projet)
- [ ] Indicateurs : temps moyen entre statuts, charge par agent
- [ ] Compte rendu hebdo automatique par e-mail

### Technique

- [ ] Stockage objets (Garage / MinIO) au lieu du volume local
- [ ] Backups automatisés vers S3/Garage
- [ ] Métriques Prometheus + dashboard Grafana
- [ ] Scan antivirus (ClamAV) sur les pièces jointes
- [ ] Mise en place de Mercure pour les mises à jour temps réel multi-onglets
- [ ] Multi-mairie (mode SaaS)

### UX / accessibilité

- [ ] Audit RGAA complet par un prestataire
- [ ] Mode sombre
- [ ] Raccourcis clavier (création rapide de tâche, recherche)
- [ ] Page "déclaration d'accessibilité" obligatoire pour collectivité

## Changelog

Maintenu à part dans `CHANGELOG.md` (à créer au moment du premier tag), au format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).
