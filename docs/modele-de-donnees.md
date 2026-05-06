# Modèle de données

Vue de référence des entités Doctrine à créer dans l'application. **Ce fichier décrit la structure (types, nullabilité, relations), pas la sémantique métier** — pour les règles, voir `docs/specifications.md` §3 (le numéro de section est rappelé dans chaque entité).

## 1. Conventions transverses

### Identifiants

- **Clé primaire** : `Symfony\Component\Uid\Uuid` v7 (UUID v7 = horodaté, indexable). Type Doctrine `uuid` (Postgres natif).
- **Clés étrangères** : toujours `uuid` également, jamais d'auto-increment.

### Dates et heures

- **Tout horodatage** : `\DateTimeImmutable`, type Doctrine `datetime_immutable` (timestamp without timezone — l'app vit en `Europe/Paris`).
- **Date pure** (sans heure) : `\DateTimeImmutable`, type Doctrine `date_immutable`.
- Champs `createdAt` / `updatedAt` gérés via Gedmo Timestampable ou subscriber Doctrine maison (à trancher au Lot 0).

### Argent

- `Money` (depuis `moneyphp/money` ou VO maison) — stockage en `decimal(12, 2)` avec une devise stockée à part si jamais (en v1, EUR uniquement, donc colonne unique `decimal`).

### Énumérations

- PHP 8 backed enums (cf. §3 ci-dessous), persistés en `string` via `enumType: XxxEnum::class` (Doctrine ≥ 2.18).
- `string(64)` suffit pour tous les enum métier listés.

### Tableaux et JSON

- `string[]` (tags, restrictedToGroups…) → colonne Postgres native `text[]` via le bundle `martin-georgiev/postgresql-for-doctrine` (ou `Types::SIMPLE_ARRAY` si on veut rester portable, mais on perd l'index GIN).
- Payloads JSON (audit log) → `Types::JSON` (Postgres `jsonb`, indexable).

### Index

À poser systématiquement sur :

- `reference` (Project, Task) — unique
- `slug` (Project, WorkingGroup, Category) — unique sur le scope concerné
- `authentikId` (User) — unique
- `email` (Requester) — non-unique (déduplication via commande, pas via contrainte SQL)
- Toutes les FK (par défaut Doctrine)
- `(status, archivedAt)` sur Project et Task pour les listes filtrées

## 2. Énumérations

| PHP | Valeurs | Utilisé par |
|---|---|---|
| `ProjectStatus` | `BROUILLON`, `ACTIF`, `EN_PAUSE`, `TERMINE`, `ANNULE` | `Project.status` |
| `ProjectVisibility` | `PUBLIC_INTERNE`, `RESTRICTED` | `Project.visibility`, `Task.visibility` (autonome) |
| `TaskStatus` | `A_FAIRE`, `EN_COURS`, `BLOQUEE`, `EN_REVUE`, `TERMINE`, `ANNULEE` | `Task.status` |
| `TaskPriority` | `BASSE`, `NORMALE`, `HAUTE`, `CRITIQUE` | `Task.priority` |
| `TaskSource` | `MANUAL`, `CITIZEN_API`, `IMPORT` | `Task.source` |
| `TaskEffort` | `XS`, `S`, `M`, `L`, `XL` | `Task.estimatedEffort` |
| `MilestoneStatus` | `PREVU`, `ATTEINT`, `EN_RETARD` | `Milestone.status` |
| `AvatarSource` | `AUTO`, `LOCAL`, `AUTHENTIK`, `GRAVATAR`, `INITIALS` | `User.avatarSource` |
| `AuditCategory` | `SECURITY`, `PROJECT`, `TASK`, `USER`, `REQUESTER`, `WORKING_GROUP`, `ADMIN`, `COMMENT`, `ATTACHMENT`, `NOTIFICATION`, `SYSTEM`, `API` | `AuditEvent.category` |
| `CommentSubjectType` | `PROJECT`, `TASK` | `Comment.subjectType` |
| `NotificationChannel` | `IN_APP`, `EMAIL` | `Notification.channel`, `NotificationPreference.channel` |

## 3. Entités principales

> Légende des colonnes : **N** = nullable. **Type SQL** = type Doctrine ou Postgres ciblé.

### 3.1 Project — `projects`

Spec : `docs/specifications.md` §3.1.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `reference` | `string` | `varchar(12)` | ✗ | Unique, format `P-YYYY-NNN` (préfixe stocké, `#` purement d'affichage). Séquence Postgres `project_reference_seq_<year>` (cf. specs §8.14) |
| `slug` | `string` | `varchar(255)` | ✗ | Unique |
| `title` | `string` | `varchar(255)` | ✗ | |
| `summary` | `?string` | `varchar(255)` | ✓ | |
| `description` | `?string` | `text` | ✓ | Markdown |
| `status` | `ProjectStatus` | `varchar(32)` | ✗ | enum |
| `visibility` | `ProjectVisibility` | `varchar(32)` | ✗ | enum |
| `restrictedToGroups` | `array` | `text[]` | ✓ | Groupes Authentik bruts |
| `restrictedToWorkingGroups` | `bool` | `boolean` | ✗ | défaut `false` |
| `owner` | `User` | FK `uuid` | ✗ | M2O `User` |
| `coOwners` | `Collection<User>` | M2M | ✓ | Table `project_co_owners` |
| `category` | `?Category` | FK `uuid` | ✓ | M2O `Category` |
| `labels` | `array` | `text[]` | ✓ | Étiquettes libres |
| `workingGroups` | `Collection<WorkingGroup>` | M2M | ✓ | Table `project_working_groups` |
| `startDate` | `?\DateTimeImmutable` | `date_immutable` | ✓ | |
| `dueDate` | `?\DateTimeImmutable` | `date_immutable` | ✓ | |
| `actualEndDate` | `?\DateTimeImmutable` | `date_immutable` | ✓ | Renseigné à la transition `TERMINE` |
| `archivedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | Drapeau d'archivage |
| `createdAt` / `updatedAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |
| `createdBy` / `updatedBy` | `User` | FK `uuid` | ✗ | M2O `User` |

**Index** : `reference` (unique), `slug` (unique), `(status, archivedAt)`, `owner_id`, `category_id`.

### 3.2 Task — `tasks`

Spec : `docs/specifications.md` §3.2.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `reference` | `string` | `varchar(12)` | ✗ | Unique, format `T-YYYY-NNN`. Séquence Postgres `task_reference_seq_<year>` distincte de celle de Project (cf. specs §8.14) |
| `title` | `string` | `varchar(255)` | ✗ | |
| `description` | `?string` | `text` | ✓ | Markdown |
| `status` | `TaskStatus` | `varchar(32)` | ✗ | enum |
| `priority` | `TaskPriority` | `varchar(32)` | ✗ | défaut `NORMALE` |
| `project` | `?Project` | FK `uuid` | ✓ | M2O — null = tâche autonome |
| `parentTask` | `?Task` | FK `uuid` | ✓ | M2O auto-référente (sous-tâche). Profondeur max 3 niveaux, anti-cycle vérifié. Cf. specs §3.2 |
| `visibility` | `?ProjectVisibility` | `varchar(32)` | ✓ | Obligatoire si `project=null`, sinon hérité |
| `restrictedToGroups` | `array` | `text[]` | ✓ | Si `visibility=RESTRICTED` et tâche autonome |
| `assignee` | `?User` | FK `uuid` | ✓ | M2O |
| `requester` | `?Requester` | FK `uuid` | ✓ | M2O |
| `workingGroups` | `Collection<WorkingGroup>` | M2M | ✓ | Table `task_working_groups` |
| `labels` | `array` | `text[]` | ✓ | |
| `dueDate` | `?\DateTimeImmutable` | `date_immutable` | ✓ | |
| `actualEndDate` | `?\DateTimeImmutable` | `date_immutable` | ✓ | |
| `estimatedEffort` | `?TaskEffort` | `varchar(8)` | ✓ | enum |
| `blockedReason` | `?string` | `text` | ✓ | Obligatoire si `status=BLOQUEE` |
| `lastStatusChangeAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | Pour détection stagnation |
| `publicLabel` | `?string` | `varchar(64)` | ✓ | Override manuel — null = libellé calculé depuis status |
| `source` | `TaskSource` | `varchar(32)` | ✗ | enum |
| `createdAt` / `updatedAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |
| `createdBy` | `?User` | FK `uuid` | ✓ | Null si `source=CITIZEN_API` |
| `updatedBy` | `User` | FK `uuid` | ✗ | |

**Index** : `reference` (unique), `(status, project_id)`, `assignee_id`, `requester_id`, `parent_task_id`, `(project_id, status, lastStatusChangeAt)` pour les requêtes Kanban / stagnation.

### 3.3 Milestone — `milestones`

Spec : `docs/specifications.md` §3.3.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `project` | `Project` | FK `uuid` | ✗ | M2O |
| `title` | `string` | `varchar(255)` | ✗ | |
| `description` | `?string` | `text` | ✓ | |
| `expectedAt` | `\DateTimeImmutable` | `date_immutable` | ✗ | Date prévue |
| `reachedAt` | `?\DateTimeImmutable` | `date_immutable` | ✓ | Date réelle |
| `status` | `MilestoneStatus` | `varchar(32)` | ✗ | enum |
| `position` | `int` | `integer` | ✗ | Ordre sur la frise |
| `createdAt` / `updatedAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |

### 3.4 Comment — `comments`

Spec : `docs/specifications.md` §3.4. **Polymorphe** sur Project ou Task via `(subjectType, subjectId)`.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `subjectType` | `CommentSubjectType` | `varchar(16)` | ✗ | `PROJECT` ou `TASK` |
| `subjectId` | `Uuid` | `uuid` | ✗ | ID de l'objet commenté |
| `body` | `string` | `text` | ✗ | Markdown |
| `visibleToRequester` | `bool` | `boolean` | ✗ | Défaut `false`. Pertinent uniquement si `subjectType=TASK` |
| `mentionedUserIds` | `array` | `uuid[]` | ✓ | Pour les notifications |
| `author` | `User` | FK `uuid` | ✗ | M2O |
| `editableUntil` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | `createdAt + 15 min` |
| `editedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |
| `deletedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | Soft delete (admin/auteur) |
| `createdAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |

**Index** : `(subjectType, subjectId, createdAt)` pour le fil chronologique.

### 3.5 Attachment — `attachments`

Spec : `docs/specifications.md` §3.5. Polymorphe sur Project, Task ou Comment.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `subjectType` | `string` | `varchar(16)` | ✗ | `PROJECT` / `TASK` / `COMMENT` |
| `subjectId` | `Uuid` | `uuid` | ✗ | |
| `originalName` | `string` | `varchar(255)` | ✗ | Nom du fichier uploadé |
| `storagePath` | `string` | `varchar(255)` | ✗ | Chemin opaque côté `AttachmentStorage` |
| `mimeType` | `string` | `varchar(128)` | ✗ | |
| `sizeBytes` | `int` | `bigint` | ✗ | |
| `checksum` | `string` | `varchar(64)` | ✗ | SHA-256, déduplication |
| `scannedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | Si scan ClamAV effectué |
| `scanResult` | `?string` | `varchar(32)` | ✓ | `clean` / `infected` / `error` |
| `uploadedBy` | `?User` | FK `uuid` | ✓ | Null si upload via portail demandeur |
| `uploadedAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |

**Index** : `(subjectType, subjectId)`, `checksum`.

### 3.6 Category — `categories`

Spec : `docs/specifications.md` §3.6. Hiérarchique via `parent`.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `slug` | `string` | `varchar(64)` | ✗ | Unique parmi les frères |
| `name` | `string` | `varchar(128)` | ✗ | |
| `parent` | `?Category` | FK `uuid` | ✓ | M2O autoréférente |
| `position` | `int` | `integer` | ✗ | Ordre parmi les frères |
| `archivedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |
| `createdAt` / `updatedAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |

> Les **étiquettes (tags)** ne sont pas une entité : c'est le champ `labels` (`text[]`) sur Project et Task.

### 3.7 Notification — `notifications`

Spec : `docs/specifications.md` §3.7.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `recipient` | `User` | FK `uuid` | ✗ | M2O |
| `channel` | `NotificationChannel` | `varchar(16)` | ✗ | enum |
| `type` | `string` | `varchar(64)` | ✗ | Slug : `task.assigned`, `comment.mention`… |
| `subjectType` | `?string` | `varchar(16)` | ✓ | |
| `subjectId` | `?Uuid` | `uuid` | ✓ | |
| `payload` | `array` | `jsonb` | ✓ | Contexte (titre, lien, snippet…) |
| `sentAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | Null = en attente d'envoi |
| `readAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |
| `createdAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |

**Index** : `(recipient_id, readAt, createdAt)` pour le badge non-lus.

#### NotificationPreference — `notification_preferences`

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `user` | `User` | FK `uuid` | ✗ | Unique avec `(type, channel)` |
| `type` | `string` | `varchar(64)` | ✗ | Type de notification |
| `channel` | `NotificationChannel` | `varchar(16)` | ✗ | |
| `enabled` | `bool` | `boolean` | ✗ | |

**Contrainte unique** : `(user_id, type, channel)`.

### 3.8 User — `users`

Spec : `docs/specifications.md` §3.8.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK locale |
| `authentikId` | `string` | `varchar(64)` | ✗ | Unique, sub OIDC |
| `username` | `string` | `varchar(128)` | ✗ | |
| `email` | `string` | `varchar(255)` | ✗ | |
| `displayName` | `string` | `varchar(255)` | ✗ | |
| `roles` | `array` | `text[]` | ✗ | Au login on stocke `[ROLE_USER]` plus `[ROLE_ADMIN]` si l'utilisateur est dans `OIDC_ADMIN_GROUP`. Les autres rôles (`ROLE_CHEF_PROJET`, `ROLE_ACTEUR`, `ROLE_LECTEUR`) sont calculés dynamiquement par les voters et **ne sont pas stockés** ici (cf. specs §2) |
| `groupsSnapshot` | `array` | `text[]` | ✗ | Groupes Authentik au dernier login |
| `lastLoginAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |
| `disabledAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |
| `avatarPath` | `?string` | `varchar(255)` | ✓ | Upload local prioritaire |
| `authentikAvatarSourceUrl` | `?string` | `varchar(1024)` | ✓ | URL d'origine côté Authentik |
| `authentikAvatarPath` | `?string` | `varchar(255)` | ✓ | Cache local |
| `authentikAvatarFetchedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | TTL 24 h |
| `avatarSource` | `AvatarSource` | `varchar(16)` | ✗ | Défaut `AUTO` |
| `gravatarAllowed` | `bool` | `boolean` | ✗ | Défaut `true` |
| `createdAt` / `updatedAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |

**Index** : `authentikId` (unique), `email`.

### 3.9 AuditEvent — `audit_events`

Spec : `docs/specifications.md` §3.9. **Append-only**, aucun `update` ni `delete` via l'app.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `occurredAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |
| `category` | `AuditCategory` | `varchar(32)` | ✗ | enum |
| `action` | `string` | `varchar(64)` | ✗ | Slug : `user.login`, `task.assigned`… |
| `actor` | `?User` | FK `uuid` | ✓ | Null pour événements système |
| `subjectType` | `?string` | `varchar(64)` | ✓ | |
| `subjectId` | `?Uuid` | `uuid` | ✓ | |
| `payload` | `array` | `jsonb` | ✓ | Contexte (changes, from/to, …) |
| `ipAddress` | `?string` | `inet` | ✓ | Postgres `inet` natif |
| `userAgent` | `?string` | `varchar(512)` | ✓ | |

**Index** : `occurredAt` (descendant), `(category, action, occurredAt)`, `actor_id`, `(subjectType, subjectId)`.

### 3.10 Requester — `requesters`

Spec : `docs/specifications.md` §3.10.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `firstName` | `string` | `varchar(128)` | ✗ | Obligatoire |
| `lastName` | `string` | `varchar(128)` | ✗ | Obligatoire |
| `email` | `?string` | `varchar(255)` | ✓ | Au moins un de email/phone |
| `phone` | `?string` | `varchar(32)` | ✓ | Au moins un de email/phone |
| `address` | `?string` | `text` | ✓ | |
| `notes` | `?string` | `text` | ✓ | Internes (agents) |
| `consentNotifications` | `bool` | `boolean` | ✗ | Défaut `false` |
| `consentDate` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |
| `consentWithdrawnAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |
| `anonymizedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | Si anonymisé (RGPD) |
| `portalTokenHash` | `?string` | `varchar(128)` | ✓ | SHA-256 du jeton portail (Lot 6) |
| `portalTokenIssuedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |
| `portalTokenRevokedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |
| `createdAt` / `updatedAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |
| `createdBy` | `User` | FK `uuid` | ✗ | Agent qui a saisi |

**Index** : `email`, `phone`, `portalTokenHash` (unique).

### 3.11 WorkingGroup — `working_groups`

Spec : `docs/specifications.md` §3.11. Entité **auto-populée au login OIDC** (claim `groups`). Une ligne par groupe Authentik observé. L'admin active la visibilité au cas par cas.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `authentikName` | `string` | `varchar(255)` | ✗ | Unique, nom machine côté Authentik (clé de réconciliation, read-only) |
| `label` | `string` | `varchar(128)` | ✗ | Libellé affiché. Initialisé à création par humanisation de `authentikName`, éditable |
| `slug` | `string` | `varchar(64)` | ✗ | Unique, généré depuis `label` |
| `description` | `?string` | `text` | ✓ | |
| `color` | `?string` | `varchar(7)` | ✓ | Hex `#RRGGBB` |
| `icon` | `?string` | `varchar(64)` | ✓ | Emoji ou nom d'icône |
| `visible` | `bool` | `boolean` | ✗ | Défaut `false`. Toggle d'apparition dans les sélecteurs Project/Task |
| `position` | `int` | `integer` | ✗ | Ordre d'affichage parmi les visibles |
| `firstSeenAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | Premier login observé contenant ce groupe |
| `lastSeenAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | Dernier login observé (mis à jour à chaque login d'un membre) |
| `archivedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | Pour ne pas perdre l'historique |
| `createdAt` / `updatedAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |
| `updatedBy` | `?User` | FK `uuid` | ✓ | Dernier admin éditeur (null si seule la création auto a eu lieu) |

**Index** : `authentikName` (unique), `slug` (unique), `(visible, archivedAt)` pour les sélecteurs, `lastSeenAt DESC` pour le tri admin.

> Le **nombre de membres** n'est pas stocké : calculé à la volée par `SELECT COUNT(*) FROM users WHERE :authentikName = ANY(groupsSnapshot) AND disabledAt IS NULL`.

### 3.12 ExternalLink — `external_links`

Spec : `docs/specifications.md` §3.12.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `label` | `string` | `varchar(64)` | ✗ | Affiché dans le lanceur |
| `url` | `string` | `varchar(1024)` | ✗ | |
| `icon` | `?string` | `varchar(128)` | ✓ | Emoji, lettre ou nom d'icône |
| `description` | `?string` | `varchar(255)` | ✓ | Tooltip |
| `position` | `int` | `integer` | ✗ | |
| `enabled` | `bool` | `boolean` | ✗ | |
| `createdAt` / `updatedAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |

### 3.13 Following (Lot 4) — `followings`

Pour le système "suivre un projet/tâche".

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `user` | `User` | FK `uuid` | ✗ | |
| `subjectType` | `string` | `varchar(16)` | ✗ | `PROJECT` / `TASK` |
| `subjectId` | `Uuid` | `uuid` | ✗ | |
| `createdAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |

**Contrainte unique** : `(user_id, subjectType, subjectId)`.

### 3.14 CrossReference (Lot 4) — `cross_references`

Index inverse des références `#YYYY-NNN` détectées dans les contenus markdown. Reconstruit à chaque save d'une description ou d'un commentaire (subscriber Doctrine). Cf. specs §3.13.

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `sourceType` | `string` | `varchar(16)` | ✗ | `PROJECT` / `TASK` / `COMMENT` |
| `sourceId` | `Uuid` | `uuid` | ✗ | Objet contenant la référence |
| `targetType` | `string` | `varchar(16)` | ✗ | `PROJECT` / `TASK` |
| `targetId` | `Uuid` | `uuid` | ✗ | Objet référencé |
| `createdAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |

**Contrainte unique** : `(sourceType, sourceId, targetType, targetId)` — une même référence ne s'enregistre qu'une fois (mentions multiples du même `#XXX` dans une description = un seul lien).

**Index** : `(targetType, targetId, createdAt DESC)` pour la requête "objets référencés vers ici" (backlinks). `(sourceType, sourceId)` pour le diff au save.

### 3.15 ApiToken (Lot 6) — `api_tokens`

Pour les clés d'API citoyennes (à enrichir au Lot 6).

| Champ | Type PHP | Type SQL | N | Notes |
|---|---|---|---|---|
| `id` | `Uuid` | `uuid` | ✗ | PK |
| `label` | `string` | `varchar(128)` | ✗ | |
| `tokenHash` | `string` | `varchar(128)` | ✗ | SHA-256 |
| `scopes` | `array` | `text[]` | ✗ | |
| `createdBy` | `User` | FK `uuid` | ✗ | |
| `createdAt` | `\DateTimeImmutable` | `datetime_immutable` | ✗ | |
| `lastUsedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |
| `revokedAt` | `?\DateTimeImmutable` | `datetime_immutable` | ✓ | |

## 4. Tables de jointure (M2M)

| Table | Côtés | Colonnes |
|---|---|---|
| `project_co_owners` | `Project ↔ User` | `project_id`, `user_id` |
| `project_working_groups` | `Project ↔ WorkingGroup` | `project_id`, `working_group_id` |
| `task_working_groups` | `Task ↔ WorkingGroup` | `task_id`, `working_group_id` |

PK composite sur les deux colonnes, FK avec `ON DELETE CASCADE` côté entité possédante (Project, Task) — pas côté User / WorkingGroup (l'effacement reste manuel ou via archivage).

## 5. Schéma simplifié

```
                           ┌───────────┐
                           │   User    │
                           │ (Authentik│
                           │ projection)│
                           └─┬─┬───┬──┬┘
              owner/coOwner│ │   │  │ author / actor / assignee
              ┌────────────┘ │   │  └─────────────┐
              ▼              ▼   ▼                ▼
        ┌─────────┐    ┌────────┐  ┌──────────┐  ┌────────────┐
        │ Project │◄1*►│ WorkingGroup           │  │  Comment   │
        │         │    │  Group  │  └──────────┘  │ (poly: P/T)│
        └────┬────┘    └────┬────┘               └─────┬──────┘
             │1               │1*                        │
            *│               *│                          │
             ▼                ▼                          │
          ┌──────┐         ┌──────┐                     │
          │ Task │◄1*──────│      │                     │
          └──┬───┘         └──────┘                     │
             │                                            │
            *│                                            │
             ▼                                            │
        ┌──────────┐                                     │
        │ Requester│                                     │
        └──────────┘                                     │
                                                         │
        ┌────────────┐    ┌────────────┐    ┌────────────┴───┐
        │ Milestone  │    │ Attachment │    │ Notification   │
        │ (→ Project)│    │ (poly: P/T │    │ (→ User)       │
        └────────────┘    │  / Comment)│    └────────────────┘
                          └────────────┘
```

Hors-graphe (transversaux) : `Category` (parent vers Category), `AuditEvent` (référence opaque tout sujet via `subjectType/subjectId`), `ExternalLink`, `Following`, `CrossReference`, `ApiToken`, `NotificationPreference`.

## 6. Conventions d'évolution

- **Toute nouvelle entité** : ajouter une section ici **dans la même PR** que la migration Doctrine.
- **Tout nouveau champ** sur une entité existante : mettre à jour la table d'attributs ici dans la même PR.
- **Renommage / suppression** : marquer la ligne supprimée en barré dans une PR transitoire avant suppression définitive (audit trail des décisions).
- En cas de **divergence avec les specs §3** : les specs font foi. Ce fichier est une vue de référence pour l'implémentation.
