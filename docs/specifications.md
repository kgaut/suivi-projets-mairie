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

Un **projet** représente une initiative de la mairie (ex. "Refonte du site web", "Fibre dans les écoles", "Aménagement parc municipal"). C'est l'unité de regroupement des tâches.

#### Attributs

| Attribut | Type | Obligatoire | Description |
|---|---|---|---|
| `id` | UUID v7 | ✓ | Identifiant interne, immuable |
| `reference` | string (10) | ✓ (généré) | Référence lisible incrémentale annuelle, ex. `P-2026-014`, immuable |
| `slug` | string (255) | ✓ (généré) | Pour les URLs ; généré du titre, peut être édité par un admin |
| `title` | string (255) | ✓ | Titre du projet |
| `summary` | string (255) | ✗ | Résumé en une phrase, affiché dans les listes |
| `description` | text (markdown) | ✗ | Description complète |
| `status` | enum | ✓ | Voir cycle de vie ci-dessous |
| `visibility` | enum | ✓ | `public_interne` (tous authentifiés) ou `restricted` |
| `restrictedToGroups` | string[] | ✗ | Groupes Authentik autorisés si `visibility=restricted` (sinon ignoré) |
| `owner` | User | ✓ | Responsable du projet |
| `coOwners` | User[] | ✗ | Co-responsables, mêmes droits que le responsable sauf transfert d'ownership |
| `category` | Category | ✗ | Catégorie principale (taxonomie hiérarchique, cf. §3.6) |
| `labels` | string[] | ✗ | Étiquettes libres |
| `commissions` | Commission[] | ✗ | Commissions associées (cf. §3.12) |
| `startDate` | date | ✗ | Date de début prévisionnelle |
| `dueDate` | date | ✗ | Date d'échéance prévisionnelle |
| `actualEndDate` | date | ✗ (renseignée à la transition `termine`) | Date de fin réelle |
| `budgetPlanned` | money (€) | ✗ | Budget prévisionnel |
| `budgetSpent` | money (€) | ✗ | Budget consommé (saisie manuelle, pas de comptabilité) |
| `archivedAt` | datetime | ✗ | Drapeau d'archivage (orthogonal au statut) |
| `createdAt` | datetime | ✓ | |
| `createdBy` | User | ✓ | Créateur initial |
| `updatedAt` | datetime | ✓ | Dernière modification |
| `updatedBy` | User | ✓ | Auteur de la dernière modification |

#### Visibilité

- `public_interne` (par défaut) : tous les utilisateurs authentifiés voient le projet.
- `restricted` : seuls les membres d'au moins un des `restrictedToGroups` (groupes Authentik) **plus** le responsable et les co-responsables peuvent voir/éditer. Utile pour les sujets RH ou confidentiels.
- L'archivage (`archivedAt != null`) est indépendant du statut et de la visibilité : un projet archivé reste visible (s'il était visible) en lecture seule.

#### Cycle de vie

```
   ┌─────────────┐
   │  brouillon  │  ← état initial à la création
   └──────┬──────┘
          │ activer
          ▼
   ┌─────────────┐         mettre en pause          ┌─────────────┐
   │    actif    │  ─────────────────────────────►  │   en_pause  │
   │             │  ◄─────────────────────────────  │             │
   └──┬──────┬───┘             reprendre            └──────┬──────┘
      │      │                                              │
      │      │ clôturer (toutes tâches non bloquantes       │
      │      │  doivent être en termine/annule)             │
      │      ▼                                              │
      │   ┌─────────────┐                                   │
      │   │   termine   │  (terminal — édition admin only)  │
      │   └─────────────┘                                   │
      │                                                     │
      │ annuler                            annuler          │
      ▼                                                     ▼
   ┌─────────────┐                                   ┌─────────────┐
   │   annule    │  ◄──────────────────────────────  │             │
   └─────────────┘                                   └─────────────┘
```

#### Statuts détaillés

| Statut | Signification | Édition possible | Tâches modifiables |
|---|---|---|---|
| `brouillon` | Esquisse en préparation, peu visible | ✓ | ✓ |
| `actif` | En cours d'exécution | ✓ | ✓ |
| `en_pause` | Suspendu temporairement (attente arbitrage, budget, partenaire) | ✓ (champs métadonnées) | ✗ (les tâches ne peuvent pas changer de statut) |
| `termine` | Clôturé avec succès, lecture seule | Admin uniquement | ✗ |
| `annule` | Abandonné, lecture seule | Admin uniquement | ✗ |

#### Règles de transition

- `brouillon → actif` : "Activer". Vérifie que `owner`, `title` sont renseignés.
- `actif → en_pause` : "Mettre en pause". Demande un motif (texte libre, stocké dans le payload de l'événement `project.status_changed`).
- `en_pause → actif` : "Reprendre".
- `actif → termine` : "Clôturer". Bloque si une tâche du projet est dans un statut non terminal (`a_faire`, `en_cours`, `en_revue`, `bloquee`) **sauf** si l'utilisateur coche "ignorer les tâches restantes" (avec confirmation, audit trail).
- `actif | en_pause | brouillon → annule` : "Annuler". Demande un motif. Les tâches du projet basculent automatiquement en `annulee`.
- `termine → actif` ou `annule → actif` : interdit. Si réouverture nécessaire, créer un nouveau projet.

#### Droits par rôle

| Action | `ROLE_LECTEUR` | `ROLE_AGENT` | `ROLE_CHEF_PROJET` | `ROLE_ADMIN` |
|---|---|---|---|---|
| Voir un projet visible | ✓ | ✓ | ✓ | ✓ |
| Créer un projet | ✗ | ✗ | ✓ | ✓ |
| Éditer un projet dont je suis owner/coOwner | n/a | ✓ | ✓ | ✓ |
| Éditer n'importe quel projet | ✗ | ✗ | ✗ | ✓ |
| Transférer l'ownership | ✗ | ✗ | ✓ (si owner) | ✓ |
| Archiver / désarchiver | ✗ | ✗ | ✓ (si owner) | ✓ |
| Modifier un projet en `termine`/`annule` | ✗ | ✗ | ✗ | ✓ |
| Voir un projet `restricted` sans appartenir aux groupes | ✗ | ✗ | ✗ | ✓ |

#### Actions

- Créer, éditer, archiver, désarchiver
- Transférer l'ownership (avec audit trail)
- Dupliquer (copie en `brouillon` sans tâches)
- Exporter PDF (Lot 5) / CSV (Lot 5)
- Suivre / ne plus suivre (Lot 4)

### 3.2 Tâche

Une **tâche** est une unité de travail rattachée à un projet. Elle représente une action concrète à mener, peut être assignée à un agent, et peut découler d'une demande externe (cf. Demandeur §3.10).

#### Attributs

| Attribut | Type | Obligatoire | Description |
|---|---|---|---|
| `id` | UUID v7 | ✓ | Identifiant interne, immuable |
| `reference` | string (10) | ✓ (généré) | Référence lisible, ex. `T-2026-0042`, immuable, incrémentale annuelle |
| `title` | string (255) | ✓ | Titre |
| `description` | text (markdown) | ✗ | Détail de la tâche |
| `status` | enum | ✓ | Voir cycle de vie ci-dessous |
| `priority` | enum | ✓ | `basse` / `normale` (défaut) / `haute` / `critique` |
| `project` | Project | ✓ | Projet parent (cf. question ouverte #1) |
| `assignee` | User | ✗ | Agent assigné |
| `requester` | Requester | ✗ | Demandeur externe (cf. §3.10) |
| `commissions` | Commission[] | ✗ | Héritées du projet par défaut au moment de la création, surchargeables (cf. §3.12) |
| `labels` | string[] | ✗ | Étiquettes libres (peuvent être héritées du projet) |
| `dueDate` | date | ✗ | Échéance |
| `actualEndDate` | date | ✗ (renseignée à la transition `termine`) | Date de fin effective |
| `estimatedEffort` | enum | ✗ | T-shirt sizing : `XS` / `S` / `M` / `L` / `XL` (pas d'estimation en heures, trop fragile) |
| `blockedReason` | text | ✗ | Motif obligatoire si `status=bloquee` |
| `lastStatusChangeAt` | datetime | ✓ | Pour les indicateurs de stagnation |
| `publicLabel` | enum | ✗ | Mappage côté demandeur (cf. §3.10 : "Reçu" / "En traitement" / "Traité") — calculé automatiquement depuis `status` mais surchargeable au cas par cas |
| `createdAt` | datetime | ✓ | |
| `createdBy` | User | ✓ | |
| `updatedAt` | datetime | ✓ | |
| `updatedBy` | User | ✓ | |

#### Cycle de vie

```
   ┌─────────────┐
   │   a_faire   │  ← état initial à la création
   └──────┬──────┘
          │ démarrer
          ▼
   ┌─────────────┐    bloquer (avec motif)    ┌─────────────┐
   │  en_cours   │  ──────────────────────►   │   bloquee   │
   │             │  ◄──────────────────────   │             │
   └──┬──────────┘         débloquer          └─────────────┘
      │
      │ envoyer en revue (optionnel)
      ▼
   ┌─────────────┐
   │  en_revue   │
   └──┬──────┬───┘
      │      │ rejeter (revient en_cours)
      │      └────────────────────────────────────► en_cours
      │ valider
      ▼
   ┌─────────────┐
   │   termine   │  (terminal)
   └─────────────┘

  À tout moment depuis a_faire / en_cours / en_revue / bloquee :
                    │
                    │ annuler
                    ▼
              ┌─────────────┐
              │   annulee   │  (terminal)
              └─────────────┘
```

#### Statuts détaillés

| Statut | Signification | Apparaît dans le Kanban |
|---|---|---|
| `a_faire` | À démarrer | colonne "À faire" |
| `en_cours` | En traitement par l'assigné | colonne "En cours" |
| `bloquee` | Suspendue, motif obligatoire | colonne "Bloquée" (visuel rouge) |
| `en_revue` | Travail fait, attente validation | colonne "En revue" |
| `termine` | Validée et clôturée | colonne "Terminé" |
| `annulee` | Abandonnée | masquée par défaut, filtrable |

#### Règles de transition

- `a_faire → en_cours` : "Démarrer". Si `assignee` est vide, l'utilisateur courant s'assigne.
- `en_cours → bloquee` : "Bloquer". `blockedReason` obligatoire (champ texte).
- `bloquee → en_cours` : "Débloquer". Le motif reste consultable dans l'historique.
- `en_cours → en_revue` : "Envoyer en revue". Optionnel — on peut clôturer directement depuis `en_cours` sur une tâche simple.
- `en_revue → en_cours` : "Renvoyer". Demande un commentaire.
- `en_cours | en_revue → termine` : "Clôturer". Renseigne `actualEndDate`. Si une revue est requise (cf. paramètre projet), seuls les utilisateurs autres que l'assignée et créateur peuvent valider depuis `en_revue`. 🟡 À confirmer.
- `* → annulee` : "Annuler". Demande un motif. Statut terminal.
- Réouverture d'une tâche `termine` ou `annulee` : interdite. Créer une nouvelle tâche.

#### Contraintes de cycle

- Une tâche ne peut pas changer de statut si son projet est en `en_pause`, `termine` ou `annule`.
- Si le projet bascule en `annule`, toutes ses tâches non terminales basculent automatiquement en `annulee` (avec un événement audit `task.cascade_cancelled`).
- Une tâche en `bloquee` depuis plus de N jours apparaît dans le dashboard "alertes" (paramètre, défaut 14 jours).

#### Droits par rôle

| Action | `ROLE_LECTEUR` | `ROLE_AGENT` | `ROLE_CHEF_PROJET` | `ROLE_ADMIN` |
|---|---|---|---|---|
| Voir une tâche du projet visible | ✓ | ✓ | ✓ | ✓ |
| Créer une tâche dans un projet visible | ✗ | ✓ | ✓ | ✓ |
| Modifier une tâche dont je suis assignee | n/a | ✓ | ✓ | ✓ |
| Modifier une tâche d'un projet dont je suis owner/coOwner | n/a | ✓ | ✓ | ✓ |
| Modifier toute tâche | ✗ | ✗ | ✗ | ✓ |
| Réassigner | ✗ | ✓ (mes tâches) | ✓ (toutes les tâches du projet) | ✓ |
| Annuler | ✗ | ✗ | ✓ | ✓ |

#### Actions

- Créer, éditer, supprimer (admin uniquement, déclenchée → bascule plutôt en `annulee`)
- Changer le statut, l'assigné, la priorité
- Associer / dissocier un demandeur
- Surcharger les commissions (par défaut héritées du projet)
- Commenter (Lot 4)
- Joindre des fichiers (Lot 4)
- Suivre / ne plus suivre (Lot 4)
- Exporter (CSV/PDF, Lot 5)

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

- **Propriétés** : `id`, `occurredAt`, `category` (`security` / `project` / `task` / `user` / `requester` / `commission` / `admin` / `comment` / `attachment` / `notification` / `system` / `api`), `action` (slug : `user.login`, `project.created`, `task.assigned`…), `actor` (User, nullable pour événements système), `subjectType` + `subjectId` (objet concerné, nullable), `payload` (JSON contextualisé, ex. ancien et nouveau statut), `ipAddress`, `userAgent`.
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
| `project.status_changed` | Transition de statut | `{ from, to, reason? }` |
| `project.archived` | Archivage | `{}` |
| `project.unarchived` | Désarchivage | `{}` |
| `project.owner_transferred` | Transfert de l'ownership | `{ from, to }` |
| `project.coowner_added` / `project.coowner_removed` | Co-responsables | `{ userId }` |
| `project.commission_linked` / `project.commission_unlinked` | Lien commission | `{ commissionId }` |
| `project.cascade_cancelled_tasks` | Tâches automatiquement annulées suite à `project.annule` | `{ taskCount }` |

**Catégorie `task`** (Lot 1)

| Slug | Quand | Payload |
|---|---|---|
| `task.created` | Création | `{ title, projectId, requesterId? }` |
| `task.updated` | Édition | `{ changes: {...} }` |
| `task.status_changed` | Transition de statut | `{ from, to, reason? }` |
| `task.blocked` | Passage en `bloquee` | `{ reason }` |
| `task.unblocked` | Sortie de `bloquee` | `{}` |
| `task.assigned` | (Re)assignation | `{ from, to }` |
| `task.priority_changed` | Changement de priorité | `{ from, to }` |
| `task.requester_linked` | Demandeur associé à la tâche | `{ requesterId }` |
| `task.requester_unlinked` | Demandeur dissocié | `{ requesterId }` |
| `task.commission_changed` | Modification des commissions associées | `{ added: [...], removed: [...] }` |
| `task.cascade_cancelled` | Annulée automatiquement par cascade projet | `{ projectId }` |
| `task.deleted` | Suppression (si autorisée) | `{}` |

**Catégorie `requester`** (Lot 1, complétée Lot 4 et Lot 6)

| Slug | Quand | Payload |
|---|---|---|
| `requester.created` | Création d'un demandeur | `{ firstName, lastName, hasEmail, hasPhone }` |
| `requester.updated` | Édition des infos | `{ changes: {...} }` (sans valeurs nominatives) |
| `requester.consent_granted` | Acceptation des notifications | `{ channel: "email" }` |
| `requester.consent_withdrawn` | Désabonnement | `{ channel: "email", source: "email_link" / "agent" }` |
| `requester.anonymized` | Suppression GDPR (anonymisation) | `{ requesterId }` |
| `requester.notification_sent` | E-mail envoyé au demandeur (Lot 4) | `{ taskId, type: "status_changed" / ... }` |
| `requester.token_generated` | Génération du jeton portail (Lot 6) | `{}` |
| `requester.token_revoked` | Révocation du jeton (Lot 6) | `{ reason }` |
| `requester.portal.viewed` | Accès au portail via jeton (Lot 6) | `{ taskId? }` (volume — voir §filtrage) |
| `requester.portal.commented` | Commentaire posté depuis le portail (Lot 6) | `{ taskId, commentId }` |

**Catégorie `commission`** (Lot 1)

| Slug | Quand | Payload |
|---|---|---|
| `commission.created` | Création d'une commission | `{ name, slug }` |
| `commission.updated` | Édition d'une commission (hors mapping) | `{ changes: {...} }` |
| `commission.archived` / `commission.unarchived` | Archivage | `{}` |
| `commission.mapping_changed` | Modification des `mappedGroups` | `{ added: [...], removed: [...] }` |

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

### 3.10 Demandeur (Requester)

Personne **externe** à l'administration à l'origine d'une demande matérialisée par une tâche. Distinct du `User` interne (qui lui est authentifié via Authentik). Typiquement : un habitant, un commerçant, une association.

- **Cas d'usage** : un agent reçoit un appel/courrier/mail d'un habitant, crée une tâche dans l'outil et y associe le demandeur. Le suivi du dossier est ensuite tracé.
- **Propriétés** :
  - `firstName` — prénom (obligatoire)
  - `lastName` — nom (obligatoire)
  - `email` — courriel (optionnel mais voir règle ci-dessous)
  - `phone` — téléphone (optionnel mais voir règle ci-dessous)
  - `address` — adresse postale (optionnel, utile pour les signalements géolocalisés)
  - `notes` — commentaires libres internes (visible uniquement par les agents)
  - `createdAt`, `createdBy` (l'agent qui a saisi)
  - `consentNotifications` (booléen) — le demandeur a-t-il accepté de recevoir des notifications par e-mail ? (cf. §5.4 RGPD)
  - `consentDate`, `consentWithdrawnAt`
- **Règles de validation** :
  - Au moins **un** des champs `email` ou `phone` est obligatoire (sinon impossible de recontacter).
  - L'e-mail doit être valide.
  - Le téléphone est stocké au format brut (pas de validation stricte E.164 en v1) mais affiché formaté.
- **Déduplication** :
  - Un demandeur est identifié par e-mail ou téléphone normalisé. À la création, l'agent voit un autocomplete sur les demandeurs existants. S'il choisit de créer quand même un doublon, c'est autorisé (un même nom-prénom peut être plusieurs personnes).
  - Une commande `app:requesters:dedupe` (interactive) permet de fusionner les doublons détectés a posteriori.
- **Lien avec Task** :
  - Une tâche a 0 ou 1 demandeur (relation many-to-one, `nullable`).
  - Un demandeur peut être lié à plusieurs tâches (historique de ses demandes).
- **Actions sur le demandeur** :
  - CRUD agent / admin
  - Vue "fiche demandeur" listant toutes les tâches associées
  - Modification des consentements (avec audit obligatoire)
  - Suppression : interdite si des tâches y sont rattachées ; à la place, **anonymisation** (les champs nominatifs sont vidés, mais l'objet reste pour préserver l'historique des tâches).

#### Notifications au demandeur (Lot 4)

- Si `email` est renseigné **et** `consentNotifications=true`, le demandeur reçoit un e-mail à chaque transition de statut significative de sa demande (ex. `a_faire → en_cours`, `en_cours → termine`).
- L'e-mail contient un lien d'accès au **portail demandeur** (cf. ci-dessous).
- Modèle d'e-mail unique, sobre, identité visuelle de la mairie, lien de désabonnement (révocation du consentement).

#### Portail demandeur via jeton (Lot 6)

Permet au demandeur, sans compte ni mot de passe, de consulter et commenter sa demande.

- **Mécanisme** : à la création du demandeur (ou à la première association à une tâche), génération d'un **jeton aléatoire** (32 octets, base62, ~43 caractères). Stocké hashé en base, l'URL contient la version claire.
- **URL type** : `https://projets.mairie.example.fr/suivi/{jeton}`. La page liste les tâches du demandeur, leur statut, l'historique public (commentaires marqués comme visibles par le demandeur), et permet d'ajouter un commentaire.
- **Visibilité** : seuls les commentaires explicitement marqués **public** par un agent (case à cocher "visible par le demandeur") sont visibles. Les commentaires internes restent cachés.
- **Durée de validité** : tant qu'au moins une tâche du demandeur n'est pas clôturée, le jeton reste actif. À clôture de la dernière tâche, le jeton expire 30 jours après.
- **Révocation** : un agent peut révoquer manuellement le jeton (régénération possible).
- **Sécurité** :
  - Rate limiting strict sur ces routes (Symfony RateLimiter + Redis).
  - Lien jeton **HTTPS uniquement**, jamais en clair dans les logs.
  - Jeton à entropie élevée, comparaison `hash_equals` côté serveur.
  - Pas d'auto-complétion / cache navigateur (`Cache-Control: no-store`).
- **Question ouverte** : le demandeur peut-il joindre une pièce (photo de signalement) depuis le portail ? Recommandation : **oui en Lot 6** mais avec scan antivirus + types restreints + taille max 5 Mo.

### 3.11 Commission

Une **commission** représente une instance de travail thématique de la mairie (commission jeunesse, commission urbanisme, commission finances, commission travaux…). Composée d'élus et/ou d'agents, elle peut être responsable de projets et de tâches.

L'appartenance à une commission est **dérivée des groupes Authentik** : un utilisateur appartient à une commission si au moins un de ses groupes Authentik est mappé à cette commission. **Aucune gestion de membres dans l'app** — Authentik reste la source de vérité.

#### Attributs

| Attribut | Type | Obligatoire | Description |
|---|---|---|---|
| `id` | UUID v7 | ✓ | |
| `slug` | string (64) | ✓ | Pour URLs / filtres, ex. `jeunesse`, `urbanisme` |
| `name` | string (128) | ✓ | Libellé affiché, ex. "Commission Jeunesse" |
| `description` | text | ✗ | Présentation, périmètre |
| `color` | string (hex) | ✗ | Pour affichage (badges colorés en liste) |
| `icon` | string | ✗ | Emoji ou nom d'icône |
| `mappedGroups` | string[] | ✗ | Liste de **noms de groupes Authentik** mappés à cette commission |
| `position` | int | ✓ | Ordre d'affichage |
| `archivedAt` | datetime | ✗ | Pour ne pas perdre l'historique d'une commission dissoute |
| `createdAt` / `createdBy` / `updatedAt` / `updatedBy` | | ✓ | |

#### Mapping vers les groupes Authentik

- Le mapping est géré par les **admins** depuis l'interface (pas de `.env` : ça évolue à chaque mandat).
- Pour chaque commission, l'admin saisit (ou choisit dans une liste) **un ou plusieurs noms de groupes Authentik**.
- Saisie : champ texte multi-valeur. Pas d'autocomplete depuis Authentik en v1 (éviterait un appel API à chaque ouverture du formulaire). En v1.x on pourra brancher l'API Authentik pour suggérer les groupes existants.
- L'admin peut ajouter/retirer des groupes à tout moment ; les permissions des utilisateurs déjà connectés se mettent à jour à leur prochain login (ou via une commande de réconciliation manuelle).

#### Calcul de l'appartenance

Au login OIDC, on récupère la liste des groupes Authentik de l'utilisateur (claim `groups`). Pour chaque commission active, on vérifie l'intersection avec `mappedGroups` :

```
userCommissions = [
  commission for commission in Commission.findActive()
  if intersect(user.authentikGroups, commission.mappedGroups) != empty
]
```

Cette liste est stockée en cache Redis avec TTL aligné sur la session, exposée dans `User::getCommissions()`.

#### Liens avec Project et Task

- **Project** : relation many-to-many `Project ↔ Commission`. Un projet peut être co-piloté par plusieurs commissions (ex. un projet de skate-park concerne Jeunesse + Travaux). Champ optionnel.
- **Task** : relation many-to-many `Task ↔ Commission`. À la création d'une tâche, les commissions du projet parent sont **héritées par défaut** mais l'utilisateur peut les modifier.

#### Filtrage et navigation

- Filtre "ma/mes commission(s)" sur les listes Projects et Tasks (pré-coché si l'utilisateur appartient à une seule commission).
- Vue dédiée par commission : `/commissions/<slug>` listant projets + tâches en cours, avec indicateurs (nb projets actifs, tâches en retard…).
- Affichage des badges commission sur les fiches Project et Task (couleur + nom).

#### Droits

- Voir la liste des commissions : tous les utilisateurs.
- Créer / éditer / archiver une commission, modifier son mapping : `ROLE_ADMIN` uniquement.
- Aucune restriction de visibilité Project/Task basée sur l'appartenance commission par défaut (les commissions sont **organisationnelles**, pas un mécanisme de contrôle d'accès — pour ça on a `visibility=restricted` sur le Project).

#### Cas particuliers à anticiper

- **Mapping orphelin** : un groupe Authentik mappé à une commission est supprimé côté Authentik → l'admin voit un avertissement dans la fiche commission (groupe inconnu).
- **Renommage de groupe Authentik** : casse le mapping. À documenter (changer le mapping côté app après).
- **Commission sans groupe mappé** : autorisé, mais alors personne n'y appartient sauf manipulations admin futures.

### 3.12 Menu d'outils externes (lanceur d'applications)

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

#### Catégories de données

| Catégorie | Source | Données | Base légale |
|---|---|---|---|
| Agents/élus (`User`) | Authentik | nom, prénom, e-mail, identifiant, groupes | Mission de service public |
| **Demandeurs (`Requester`)** | Saisie agent ou portail | nom, prénom, e-mail, téléphone, adresse, notes internes | Mission de service public + intérêt légitime ; consentement explicite pour les notifications e-mail |
| Audit log | Activité applicative | identifiants des acteurs, contenu d'actions | Obligation de traçabilité |

#### Règles de traitement

- Pas de tracking analytics tiers.
- Logs applicatifs purgés à 90 jours, audit log conservé 3 ans.
- **Suppression d'un User (agent)** : anonymisation des contributions (libellé `Utilisateur supprimé`), pas de hard delete.
- **Suppression d'un Requester** : impossible si des tâches y sont rattachées → anonymisation (champs nominatifs vidés, lien préservé).
- **Demandeurs** : durée de conservation par défaut = durée de vie du dossier le plus récent + 5 ans (justifiable par la durée de prescription administrative). Une commande `app:requesters:purge --inactive-since=<date>` permet la purge programmée.
- **Consentement** notifications : opt-in explicite, traçabilité dans l'audit log, désabonnement en un clic depuis chaque e-mail.
- **Droit d'accès / rectification / effacement** : un demandeur peut écrire à la mairie ; une commande `app:requesters:export <id>` produit son dossier complet, `app:requesters:erase <id>` lance la procédure d'anonymisation.

#### Documentation à produire

- 🟡 Registre des traitements (à compléter par le DPO de la mairie)
- 🟡 Mention CNIL et lien vers la politique de confidentialité dans le footer
- 🟡 Mention sur le portail demandeur expliquant la finalité et les droits
- 🟡 Page "déclaration d'accessibilité" (RGAA) obligatoire

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
8. **Demandeur** : nom + prénom **obligatoires** ou peut-on accepter un signalement anonyme (utile pour les habitants qui ne veulent pas se déclarer) ? (recommandation : nom obligatoire, prénom optionnel ; au moins un canal de contact mail/tél obligatoire)
9. **Demandeur portail** : autorise-t-on le commentaire depuis le portail (Lot 6) ou consultation seule ? (recommandation : oui, commentaire autorisé, modéré côté agent qui voit les nouveaux commentaires demandeur dans son flux)
10. **Demandeur portail pièces jointes** : autoriser à joindre une photo (signalement type "trou dans la chaussée") ? (recommandation : oui, mais en Lot 6 avec restrictions strictes)
11. **Statuts visibles par le demandeur** : tous les statuts internes (`a_faire`, `en_cours`, `en_revue`…) sont-ils exposés tels quels au demandeur, ou faut-il un libellé "demandeur-friendly" ? (recommandation : libellés simplifiés mappés depuis les statuts internes — "Reçu" / "En traitement" / "Traité")
12. **Tâche en revue** : la transition `en_revue → termine` requiert-elle obligatoirement un valideur différent de l'assigné·e ? Configurable par projet ? (recommandation : non par défaut, on autorise l'auto-validation, sauf si le projet active "revue obligatoire" dans ses paramètres)
13. **Estimation d'effort** : t-shirt sizes (XS/S/M/L/XL) suffisants ou tu veux une estimation en heures/jours ? (recommandation : t-shirt, plus humain)
14. **Référence Project/Task** : préfixe `P-`/`T-` ou un autre format (ex. inspiré de l'existant si tu en as) ? Sequence remise à zéro chaque année ? (recommandation : `P-YYYY-NNN` / `T-YYYY-NNNN`, reset annuel)
15. **Mapping Commission ↔ Authentik** : saisie libre du nom du groupe en v1 (recommandation), ou autocomplete via API Authentik dès le départ (plus de complexité, dépendance réseau au formulaire admin) ?
16. **Visibilité par commission** : les commissions servent-elles **uniquement** à organiser/filtrer (recommandation), ou veux-tu qu'on puisse rendre un projet visible **uniquement** aux membres d'une commission donnée (ce qui ferait doublon avec `visibility=restricted`, complexifierait les voters) ?
