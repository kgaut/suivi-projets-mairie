# Roadmap

> Document vivant. Idées non priorisées en bas, regroupées en lots dans la section principale. Chaque lot livré donnera lieu à un **tag git annoté** (`v0.X.0`).

## Convention

- Un **lot** = un ensemble cohérent de tâches livrables ensemble = **un tag** sur `main`.
- Une **tâche** = une issue GitHub = une branche `feat/<n°>-<slug>` = une PR.
- Statut des lots : `📅 prévu` / `🚧 en cours` / `✅ livré`.

## Lots planifiés

### Lot 0 — Fondations · `v0.1.0` · 📅 prévu

Squelette technique opérationnel + section administration de base + infrastructure d'audit log. Pas encore de gestion de projets/tâches.

**Squelette technique**

- [ ] `composer create-project symfony/skeleton` + structure `src/` (Controller / Application / Domain / Infrastructure / Security)
- [ ] Dockerfile FrankenPHP + `docker-compose.dev.yml` + `docker-compose.prod.yml`
- [ ] `Makefile` (install, migrate, test, stan, cs, shell, reset)
- [ ] Configuration Doctrine + Postgres + premières migrations
- [ ] Configuration Redis (cache + sessions + Messenger)
- [ ] Layout Twig responsive (mobile-first, burger menu < 1024 px, header avec menu utilisateur, footer)
- [ ] Symfony UX Turbo + Stimulus en place + composant Hello World
- [ ] Choix du framework CSS (Tailwind vs Bootstrap) tranché et intégré
- [ ] CI GitHub Actions (lint + tests + phpstan + composer audit + deptrac)
- [ ] CI GitLab miroir
- [ ] Build + push image GHCR sur tag
- [ ] Doc d'install à jour (`docs/local-dev.md`, `docs/deploiement.md`, `docs/authentik.md`)

**Authentification**

- [ ] Intégration Authentik OIDC (bundle `drenso/symfony-oidc-bundle` à confirmer)
- [ ] Mapping groupes Authentik → rôles Symfony selon `OIDC_GROUP_ROLE_MAPPING`
- [ ] Entité `User` (projection locale d'Authentik : `authentikId`, `username`, `email`, `displayName`, `roles`, `groupsSnapshot`, `lastLoginAt`)
- [ ] Réconciliation utilisateur au login (création si nouveau, mise à jour sinon)
- [ ] Page `/profile` (groupes Authentik affichés, lien vers Authentik)
- [ ] Logout local + logout SSO côté Authentik
- [ ] Voter de base + handlers `AccessDeniedException`

**Section administration (réservée `ROLE_ADMIN`)**

- [ ] Layout admin distinct (sidebar de navigation admin)
- [ ] Liste des utilisateurs : nom, e-mail, groupes, rôles dérivés, dernière connexion, statut (actif/désactivé), lien direct vers la fiche Authentik
- [ ] Tri et filtres sur la liste utilisateurs (par rôle, par groupe, recherche par nom/email)
- [ ] Détail utilisateur : historique de connexions, contributions à venir (sera enrichi plus tard)
- [ ] Pas de création/édition d'utilisateur dans l'app (c'est Authentik qui gère)
- [ ] **Gestion des liens externes** (CRUD `ExternalLink` : libellé, URL, icône, description, position, restriction par rôle, actif/inactif)

**Menu d'outils externes (front)**

- [ ] Composant Twig "lanceur d'apps" intégré dans le header (icône grille → dropdown)
- [ ] Lecture des `ExternalLink` actifs filtrés par rôles de l'utilisateur courant
- [ ] Vue mobile adaptée (panneau plein écran plutôt que dropdown)
- [ ] Cible `_blank` + `rel="noopener noreferrer"` pour la sécurité

**Préparation de l'audit log (sans stockage encore)**

> Le stockage et l'UI viennent au **Lot 2** (lot dédié). Mais on définit dès maintenant les classes d'événements applicatifs et on les émet depuis le code de sécurité, pour ne pas avoir à revenir sur ce code plus tard.

- [ ] Définition des classes d'événements applicatifs côté `Application/Event/` (`UserLoggedIn`, `UserLoggedOut`, `LoginFailed`, `AccessDenied`, voir liste complète dans `docs/specifications.md` §3.9)
- [ ] Dispatch via Symfony EventDispatcher dans le flux de sécurité OIDC
- [ ] Pas de subscriber persistant à ce stade (ou un subscriber `dev` qui log dans la console)

**Critère de fin** : un nouvel arrivant clone le repo, lance `make install`, se connecte via Authentik, voit ses groupes sur `/profile`. Un admin accède à `/admin`, voit la liste des utilisateurs. Les événements de sécurité sont émis dans Symfony (vérifiable via `bin/console debug:event-dispatcher`). L'application est utilisable confortablement sur smartphone. La CI est verte. Une image taguée `v0.1.0` est publiée sur GHCR.

### Lot 1 — Projets, tâches et demandeurs · `v0.2.0` · 📅 prévu

CRUD de base avec assignation, statuts et gestion des demandeurs externes.

**Projets et tâches**

- [ ] Entité `Project` + migration + fixtures de dev
- [ ] Entité `Task` + migration + fixtures de dev
- [ ] CRUD Project (liste, fiche, créer, éditer, archiver)
- [ ] CRUD Task (liste filtrée par projet, fiche, créer, éditer)
- [ ] Statuts (workflow Symfony) sur Project et Task
- [ ] Assignation d'une tâche à un utilisateur
- [ ] Voters : qui peut éditer quoi
- [ ] Vue "Mes tâches"

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

- [ ] **Émission des événements applicatifs** : `project.*`, `task.*` (incluant `task.requester_linked/unlinked`), `requester.created/updated/anonymized` (cf. `docs/specifications.md` §3.9)
- [ ] Tests fonctionnels du parcours complet (création projet → tâche → association demandeur)
- [ ] Vues mobile testées (liste projets en cartes, formulaires adaptés)

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
- [ ] Pièces jointes (upload, prévisualisation, suppression)
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
- [ ] Affichage des tâches : statut, dates, historique des commentaires marqués "visible par le demandeur"
- [ ] Formulaire de commentaire depuis le portail (optionnel, pièce jointe possible avec scan + restrictions)
- [ ] Bandeau RGPD/finalité sur la page
- [ ] Headers `Cache-Control: no-store` sur ces routes
- [ ] Expiration automatique du jeton 30 j après clôture de la dernière tâche
- [ ] Révocation manuelle par un agent (régénération possible)
- [ ] Lien d'accès au portail injecté dans tous les e-mails de notification du Lot 4

**API citoyenne (préparation)**

- [ ] Mise en place d'API Platform sur les ressources publiques (Project en lecture)
- [ ] Authentification API par token (clés générées par les admins)
- [ ] Endpoint `POST /api/signalements` qui crée un Requester (ou réutilise par dédup e-mail) + une Task dans un projet "Signalements citoyens"
- [ ] Documentation OpenAPI
- [ ] Tests d'intégration API
- [ ] Rate limiting (Symfony RateLimiter + Redis)

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
