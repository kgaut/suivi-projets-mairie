# Spécifications fonctionnelles et techniques

> Document vivant. Premier jet structuré pour itération. Les zones marquées `🟡 à préciser` attendent ton input.

## 1. Vision

Outil interne d'une mairie permettant au délégué au numérique, aux élus et aux agents de suivre les projets en cours, leurs tâches, leur avancement et les responsabilités associées. L'objectif est de **remplacer les tableurs et fils d'e-mails** par une source unique de vérité, accessible via SSO.

### Principes directeurs

1. **Simplicité d'usage** avant exhaustivité fonctionnelle. Un agent doit pouvoir créer une tâche en moins de 30 secondes.
2. **Transparence interne** : par défaut, tout le monde voit tout. Les restrictions sont l'exception.
3. **Données souveraines** : auto-hébergement, pas de SaaS tiers, conformité RGPD.
4. **Préparation de l'ouverture** : la v1 est interne, mais l'architecture anticipe une exposition future via API pour une application citoyenne de signalements.

## 2. Acteurs et rôles

Les rôles sont **dérivés des groupes Authentik**. Aucun rôle n'est géré dans l'application.

| Rôle Symfony | Groupe Authentik (à confirmer) | Description |
|---|---|---|
| `ROLE_LECTEUR` | `mairie-projets-lecteur` | Lecture seule sur tout |
| `ROLE_AGENT` | `mairie-projets-agent` | Crée et met à jour ses tâches, commente |
| `ROLE_CHEF_PROJET` | `mairie-projets-chef` | Gère un ou plusieurs projets, assigne des tâches |
| `ROLE_ADMIN` | `mairie-projets-admin` | Gère les paramètres globaux, les catégories, etc. |

> 🟡 À préciser : noms exacts des groupes Authentik que tu utilises (ou que tu vas créer). L'application peut s'adapter via mapping dans `.env`.

## 3. Concepts métier (modèle de domaine)

### 3.1 Projet

Un **projet** représente une initiative de la mairie (ex. "Refonte du site web", "Fibre dans les écoles").

- **Propriétés** : titre, description (markdown), statut (`brouillon` / `actif` / `en_pause` / `termine` / `annule`), responsable (User), date de début, date d'échéance prévisionnelle, date de fin réelle, catégorie(s), visibilité.
- **Visibilité** : `public_interne` (tous les utilisateurs authentifiés) ou `restreint` (liste explicite de groupes Authentik). Pas de visibilité externe en v1.
- **Actions** : créer, éditer, archiver, dupliquer, exporter (PDF/CSV).

### 3.2 Tâche

Une **tâche** est une unité de travail rattachée à un projet (ou autonome — à challenger).

- **Propriétés** : titre, description (markdown), statut (`a_faire` / `en_cours` / `en_revue` / `termine` / `bloquee`), priorité (`basse` / `normale` / `haute` / `critique`), assignée à (User, optionnel), créée par (User), échéance, étiquettes, projet parent (optionnel ?).
- **Actions** : créer, éditer, changer le statut, réassigner, commenter, joindre des fichiers.
- 🟡 À décider : autorise-t-on des tâches **sans projet parent** (tâches "vrac") ? Mon avis : non en v1, force l'utilisateur à choisir/créer un projet pour éviter le fourre-tout.

### 3.3 Jalon (Milestone)

Repère d'avancement sur un projet (ex. "Cahier des charges validé", "MEP préprod").

- **Propriétés** : titre, description, projet parent, date prévue, date réelle, statut (`prevu` / `atteint` / `en_retard`).
- Affiché sur une frise chronologique du projet.

### 3.4 Commentaire

- Markdown, sur Projet ou Tâche, auteur, horodatage, édition possible 15 min après publication puis verrouillé (à confirmer).
- Mentions `@utilisateur` qui déclenchent une notification.

### 3.5 Pièce jointe

- Stockée localement dans un volume Docker (`/var/uploads`), pas de S3 en v1 (à challenger si tu veux du Garage/MinIO).
- Limite : 10 Mo par fichier (à confirmer), 5 fichiers par tâche/projet.
- Types autorisés : PDF, images, bureautique (docx, xlsx, odt, ods), archives. Pas d'exécutables.

### 3.6 Catégorie / Tag

- **Catégorie** : taxonomie hiérarchique gérée par les admins (ex. "Voirie > Éclairage public").
- **Étiquette** (label/tag) : libre, créée par les utilisateurs, ex. "urgent", "subvention".

### 3.7 Notification

- En v1 : notifications dans l'app (badge en barre de nav) + e-mail.
- Déclencheurs : assignation d'une tâche, mention dans un commentaire, changement de statut sur un projet/tâche que je suis, échéance approchante (J-3, J-1).
- Préférences par utilisateur (toggle e-mail / in-app).

### 3.8 Utilisateur (vue applicative)

L'utilisateur n'est **pas géré dans l'app** : il est créé/modifié/supprimé dans Authentik. L'app conserve une projection locale pour rattacher les contributions et afficher les informations utiles.

- **Propriétés persistées** : `authentikId` (clé), `username`, `email`, `displayName`, `roles` (dérivés des groupes), `groupsSnapshot` (groupes Authentik au dernier login, pour affichage), `lastLoginAt`, `createdAt`, `disabledAt` (si désactivé côté Authentik).
- **Cycle de vie** : l'utilisateur apparaît dans la base au premier login OIDC. Si Authentik renvoie un utilisateur déjà connu (même `authentikId`), on met à jour ses infos.
- **Désactivation** : si l'utilisateur n'arrive plus à se connecter (suppression côté Authentik), il reste en base avec ses contributions intactes. Une commande `app:users:reconcile` (à venir) peut interroger l'API Authentik pour marquer les comptes orphelins.

### 3.9 Événement d'audit (audit log)

Trace immuable de toutes les actions importantes effectuées dans l'application. **Pas un log technique** (qui va dans `var/log`), mais un journal métier consultable par les admins.

- **Propriétés** : `id`, `occurredAt`, `category` (`security` / `project` / `task` / `user` / `admin` / `comment` / `attachment` / `notification` / `system` / `api`), `action` (slug : `user.login`, `project.created`, `task.assigned`…), `actor` (User, nullable pour événements système), `subjectType` + `subjectId` (objet concerné, nullable), `payload` (JSON contextualisé, ex. ancien et nouveau statut), `ipAddress`, `userAgent`.
- **Immuabilité** : aucun update ni delete via l'app, pas même par un admin. Purge possible uniquement par script DBA / commande après la durée de rétention légale.
- **Rétention** : 3 ans (à confirmer avec ta DPD).
- **Consultation** : écran admin avec filtres (catégorie, action, utilisateur, intervalle de dates, sujet) + export CSV.

#### Approche en deux temps

| Lot | Ce qui est livré |
|---|---|
| Lot 0 | Définition des **classes d'événements applicatifs** (`Application/Event/`) + dispatch via Symfony EventDispatcher dans le code de sécurité |
| Lot 1, 4, 5, 6 | Chaque feature **émet** ses propres événements applicatifs |
| **Lot 2** | Entité `AuditLog`, subscriber unique qui persiste tous ces événements, UI admin avec filtres |

Conséquence : on ne revient **pas** sur le code des features pour brancher l'audit. Le subscriber écoute simplement tous les événements de la liste ci-dessous au moment où il est mis en place.

#### Liste exhaustive des événements à enregistrer

> Référence pour les développeurs : à chaque fois qu'on implémente une fonctionnalité dans cette liste, on émet l'événement correspondant via `EventDispatcher`. La classe d'événement vit dans `src/Application/Event/`. Cette liste est appelée à grandir, c'est attendu.

**Catégorie `security`** (livrée Lot 0)

| Slug | Quand | Payload |
|---|---|---|
| `security.login.success` | Login OIDC réussi | `{ authentikId, groups }` |
| `security.login.failure` | Échec OIDC (erreur Authentik, refus) | `{ reason, attemptedEmail? }` |
| `security.logout` | Logout local ou SSO | `{}` |
| `security.access_denied` | `AccessDeniedException` levée | `{ route, requiredRoles }` |
| `security.session.expired` | Session expirée détectée | `{}` |

**Catégorie `user`** (Lot 0 partiellement, complété Lot 2)

| Slug | Quand | Payload |
|---|---|---|
| `user.first_seen` | Premier login d'un utilisateur (création locale) | `{ authentikId, email, displayName }` |
| `user.profile_updated` | Mise à jour des infos depuis Authentik au login | `{ changes: {field: {old, new}} }` |
| `user.roles_changed` | Les rôles dérivés changent suite à modification des groupes | `{ added: [...], removed: [...] }` |
| `user.disabled` | Détecté désactivé côté Authentik (commande de réconciliation) | `{ reason }` |

**Catégorie `project`** (Lot 1)

| Slug | Quand | Payload |
|---|---|---|
| `project.created` | Création d'un projet | `{ title, visibility }` |
| `project.updated` | Édition d'un projet (champs métier) | `{ changes: {field: {old, new}} }` |
| `project.status_changed` | Transition de statut | `{ from, to }` |
| `project.archived` | Archivage | `{}` |
| `project.unarchived` | Désarchivage | `{}` |
| `project.responsible_changed` | Changement de responsable | `{ from, to }` |

**Catégorie `task`** (Lot 1)

| Slug | Quand | Payload |
|---|---|---|
| `task.created` | Création | `{ title, projectId }` |
| `task.updated` | Édition | `{ changes: {...} }` |
| `task.status_changed` | Transition de statut | `{ from, to }` |
| `task.assigned` | (Re)assignation | `{ from, to }` |
| `task.priority_changed` | Changement de priorité | `{ from, to }` |
| `task.deleted` | Suppression (si autorisée) | `{}` |

**Catégorie `comment`** (Lot 4)

| Slug | Quand | Payload |
|---|---|---|
| `comment.created` | Nouveau commentaire | `{ subjectType, subjectId, mentions: [...] }` |
| `comment.edited` | Édition (dans la fenêtre de 15 min) | `{ }` |
| `comment.deleted` | Suppression par admin/auteur | `{}` |

**Catégorie `attachment`** (Lot 4)

| Slug | Quand | Payload |
|---|---|---|
| `attachment.uploaded` | Upload | `{ filename, size, mime }` |
| `attachment.deleted` | Suppression | `{ filename }` |

**Catégorie `notification`** (Lot 4)

| Slug | Quand | Payload |
|---|---|---|
| `notification.sent` | Notification envoyée (in-app ou e-mail) | `{ recipientId, channel, type }` |
| `notification.read` | Marquée comme lue | `{ notificationId }` |

**Catégorie `admin`** (Lot 0 et au-delà)

| Slug | Quand | Payload |
|---|---|---|
| `admin.category.created/updated/deleted` | Gestion des catégories | `{ name, parentId? }` |
| `admin.settings.updated` | Modification paramètre global | `{ key, oldValue, newValue }` |
| `admin.audit.exported` | Export CSV du journal | `{ filterCount, rowCount }` |

**Catégorie `api`** (Lot 6)

| Slug | Quand | Payload |
|---|---|---|
| `api.token.created` | Création d'une clé d'API | `{ tokenLabel, scopes }` |
| `api.token.revoked` | Révocation | `{ tokenLabel }` |
| `api.signalement.received` | Endpoint POST /api/signalements appelé | `{ source, taskId }` |

**Catégorie `system`**

| Slug | Quand | Payload |
|---|---|---|
| `system.maintenance.started/ended` | Mode maintenance | `{ message? }` |
| `system.migration.applied` | Migration BDD appliquée | `{ version }` |
| `system.audit.purged` | Purge manuelle du journal | `{ before, deletedCount }` |

#### Conventions techniques

- Toutes les classes d'événements héritent d'une interface `AuditableEvent` qui expose `category()`, `action()`, `subject()`, `payload()`.
- Le subscriber unique du Lot 2 écoute `AuditableEvent` (pas chaque classe individuellement) — l'ajout d'un nouvel événement au fil des lots est gratuit.
- Les payloads ne contiennent **jamais** de données sensibles (mot de passe, token, fichier). Les e-mails y sont OK (déjà connus).
- Un événement très volumineux (ex. `api.request.received` au Lot 6) sera filtré pour éviter de saturer la table — décision au cas par cas dans le subscriber.

### 3.10 Menu d'outils externes (lanceur d'applications)

Dans la barre de navigation principale, en plus du menu de l'outil, un **menu déroulant** affiche des raccourcis vers d'autres outils internes de la mairie (genre app launcher type "grille Google Apps"). Permet de circuler facilement entre les outils auto-hébergés.

- Configuré côté **administration** (et/ou via fichier de config / `.env`, à trancher).
- Chaque entrée : libellé, URL, icône (image ou emoji ou lettre), description courte (tooltip), groupe de visibilité optionnel (si tu veux cacher certains liens à certains rôles).
- Stockage : entité `ExternalLink` simple (`label`, `url`, `icon`, `description`, `position`, `restrictedToRoles[]`, `enabled`).
- UI : icône "grille" dans le header → dropdown ou panneau plein-écran sur mobile. Liens en target `_blank` avec `rel="noopener"`.
- Pas d'authentification SSO transparente attendue côté app : on suppose que l'utilisateur est authentifié sur les outils externes via Authentik (le SSO étant déjà en place pour eux aussi).
- 🟡 **À décider** : configuration via interface admin (plus pratique) ou via `.env` (plus simple pour la v0). Recommandation : **interface admin** dès le Lot 0 (entité + CRUD), reste léger à coder.

> 🟡 À remplir au fil des itérations. Pour chaque écran : objectif, données affichées, actions, règles de sécurité.

- [ ] Écran d'accueil / dashboard
- [ ] Liste des projets (filtres, tri, recherche)
- [ ] Fiche projet (onglets : tâches, jalons, fichiers, activité)
- [ ] Liste des tâches (vue tableau + vue Kanban)
- [ ] Fiche tâche
- [ ] Mes tâches (vue personnelle)
- [ ] Calendrier (échéances et jalons)
- [ ] Préférences utilisateur
- [ ] **Administration**
  - [ ] Liste des utilisateurs : nom, e-mail, groupes Authentik, rôles dérivés, dernière connexion, statut, lien direct vers la fiche dans Authentik
  - [ ] Gestion des **liens externes** affichés dans le menu d'outils (CRUD simple)
  - [ ] Journal d'événements (audit log) avec filtres (catégorie, action, utilisateur, période, recherche texte) + export CSV — **Lot 2**
  - [ ] Catégories (gestion de la taxonomie hiérarchique)
  - [ ] Paramètres globaux (à définir au fil de l'eau)
  - [ ] (plus tard) clés d'API pour la future app citoyenne

## 5. Spécifications techniques

### 5.1 Stack confirmée

- PHP 8.4, Symfony 7.x
- FrankenPHP en mode worker (1 binaire = serveur web + PHP)
- PostgreSQL 16
- Redis 7 (cache HTTP, sessions, transport Symfony Messenger)
- Twig + Symfony UX (Turbo + Stimulus + Live Components)
- Composants UI : à décider entre Tailwind et Bootstrap. 🟡 Mon avis : **Tailwind** + le pack `symfony/ux-twig-component` permet un design system maîtrisé sans dépendre d'un framework CSS lourd. Bootstrap reste OK si tu préfères des composants prêts à l'emploi.

### 5.2 Architecture applicative

```
src/
  Controller/      # fins, délèguent immédiatement
  Domain/          # entités Doctrine + Value Objects + énumérations
  Application/     # services applicatifs (use cases) — point d'entrée pour les controllers ET la future API
  Infrastructure/  # repositories, intégrations externes (Authentik, mail, stockage fichiers)
  Security/        # OIDC, voters, mapping rôles
  Twig/            # extensions Twig, composants UX
```

Cette séparation `Controller → Application → Domain ← Infrastructure` permettra d'ajouter API Platform plus tard en ne touchant qu'aux controllers/API resources.

### 5.3 Sécurité

- HTTPS obligatoire en prod (terminé par Traefik/Caddy en amont).
- CSRF activé sur tous les formulaires.
- Headers de sécurité : `Content-Security-Policy`, `X-Frame-Options`, `Strict-Transport-Security`, `Referrer-Policy`.
- Pas de mot de passe stocké : tout passe par OIDC.
- Sessions stockées dans Redis avec TTL aligné sur la durée de vie du token Authentik.
- Voters Symfony pour les contrôles d'accès objet par objet.
- Audit log : qui a fait quoi sur quel objet (table dédiée).

### 5.4 RGPD

- Données nominatives : nom, prénom, e-mail, identifiant Authentik, contributions.
- Pas de tracking analytics tiers.
- Logs applicatifs purgés à 90 jours, audit log conservé 3 ans (à valider).
- Procédure de suppression d'un compte : anonymisation des contributions (`Utilisateur supprimé`), pas de hard delete pour préserver l'historique.
- 🟡 À documenter : registre de traitement, mention CNIL côté footer.

### 5.5 Accessibilité

- Cible : **RGAA 4.1** niveau AA (obligation pour une collectivité).
- Choix techniques alignés : composants HTML natifs, ARIA quand nécessaire, contrastes vérifiés, navigation clavier complète.
- Test automatisé : `axe-core` via `pa11y-ci` dans la CI sur quelques pages critiques.

### 5.5b Responsive / mobile

- **Mobile-first** : conception en partant de l'écran le plus contraint, élargissement progressif vers desktop.
- Cibles : iPhone SE (375 px) jusqu'à grand écran 1920 px et plus. Tablettes incluses.
- Pas d'app mobile native en v1 — usage via navigateur mobile.
- Composants UI testés sur trois breakpoints (≤640 px, ≤1024 px, > 1024 px).
- Le menu de navigation passe en burger en dessous de 1024 px.
- Les tableaux longs (liste projets, audit log) sont rendus en cartes empilées sur mobile plutôt qu'en tableau scrollable horizontalement.
- Test : la CI exécute Playwright (ou équivalent) sur les pages clés en deux viewports (mobile + desktop) — à mettre en place au Lot 1, Lot 0 valide visuellement uniquement.

### 5.6 Internationalisation

- v1 : français uniquement. Mais on utilise les composants `translator` Symfony dès le début pour ne pas avoir à tout reprendre.

### 5.7 Performance

- FrankenPHP en mode worker pour éviter le bootstrap Symfony à chaque requête.
- Cache HTTP via Redis sur les listes et le dashboard.
- Doctrine : second level cache désactivé par défaut, à activer ciblé si besoin.
- Pagination obligatoire (max 50 items par défaut) sur toutes les listes.

## 6. Hors scope v1

- Portail public citoyen
- Application mobile native
- Multi-tenant / multi-mairies
- Signature électronique de documents
- Vidéoconférence intégrée
- Diagrammes de Gantt complexes (on se contente d'une frise simple)
- Export ICS du calendrier (peut venir en v1.x si demandé)

## 7. Anticipations pour les évolutions futures

- **API REST citoyenne** : tous les services applicatifs sont conçus avec des DTOs typés, pas de dépendance à `Request`/`Session`. Ajout d'API Platform sur les ressources concernées.
- **Multi-mairie** : le modèle de données n'inclut pas de notion de "tenant" en v1, mais on évite les singletons globaux qui rendraient l'évolution douloureuse.
- **Webhooks sortants** : prévoir une table `webhook_subscription` dès qu'on en aura besoin pour notifier l'app citoyenne.

## 8. Questions ouvertes (à trancher avec toi)

1. Tâches autorisées sans projet parent ? (recommandation : non)
2. Framework CSS : Tailwind ou Bootstrap ? (recommandation : Tailwind)
3. Limites pièces jointes : 10 Mo / 5 fichiers ?
4. Durée de rétention des logs / audit ?
5. Noms exacts des groupes Authentik à utiliser ?
6. Y a-t-il des intégrations existantes à prévoir (annuaire LDAP de la mairie au-delà d'Authentik, GED, parapheur) ?
7. Est-ce qu'un agent peut voir les tâches d'un autre agent par défaut ? (recommandation : oui — transparence interne)
