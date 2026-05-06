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

Les rôles sont **calculés dynamiquement à chaque action**, en fonction de l'objet (Project ou Task) sur lequel l'utilisateur agit. Aucune table de rôles n'est gérée dans l'application : un seul groupe Authentik **statique** existe (`admin_spm`) ; les autres rôles sont contextuels.

| Rôle Symfony | Calcul | Description |
|---|---|---|
| `ROLE_ADMIN` | **Statique** : membre du groupe Authentik `admin_spm` | Peut tout faire — gère les paramètres globaux, les catégories, les groupes de travail, voit même les objets `restricted` auxquels il n'appartient pas |
| `ROLE_CHEF_PROJET` | **Dynamique** : pour un objet donné, l'utilisateur est `owner` ou `coOwner` du projet (ou du projet de la tâche), ou `createdBy` d'une tâche autonome | Gère son propre projet / sa propre tâche autonome (édition, transfert d'ownership, archivage, clôture, etc.) |
| `ROLE_ACTEUR` | **Dynamique** : pour un objet donné, l'utilisateur est membre d'au moins un groupe de travail associé au projet ou à la tâche concernés | Crée des tâches dans le projet, met à jour les tâches dont il est `assignee`, commente, joint des fichiers |
| `ROLE_LECTEUR` | **Dynamique** : utilisateur authentifié n'entrant dans aucun des cas ci-dessus pour l'objet considéré | Lecture seule (si l'objet est visible) |

Le seul rôle "global" injecté dans `User::getRoles()` est `ROLE_ADMIN` (et `ROLE_USER` Symfony par défaut). Les autres rôles sont **transverses** et calculés par les voters Symfony à chaque appel `Voter::vote($subject)`. Cette approche est plus proche d'un modèle ABAC (attribute-based) que d'un RBAC strict, et évite que `ROLE_CHEF_PROJET` accordé en bloc donne accès à *tous* les projets.

#### Conséquences pratiques

- Un même utilisateur peut être **chef de projet sur le projet A**, **acteur sur le projet B** (membre d'un groupe de travail associé), et **lecteur sur le projet C** (visible mais sans appartenance), sans aucun changement de configuration côté Authentik.
- Le seul groupe Authentik à provisionner pour l'application est **`admin_spm`** (le nom est configurable via `OIDC_REQUIRED_GROUPS` / `OIDC_ADMIN_GROUP` — cf. `docs/authentik.md`).
- Les **groupes de travail** (cf. §3.11) sont la cheville ouvrière du calcul de `ROLE_ACTEUR` : pour qu'un utilisateur puisse contribuer à un projet/une tâche, il faut que cet objet soit associé à un groupe de travail dont il est membre.
- L'accès **général à l'application** reste contrôlé par le filtrage Authentik (Policy Binding + `OIDC_REQUIRED_GROUPS`, cf. §5.3) : on n'authentifie que les utilisateurs autorisés à utiliser l'outil. Le filtrage par rôle ne joue que sur ce qu'on peut faire **une fois dedans**.

## 3. Concepts métier (modèle de domaine)

> 📋 **Vue de référence pour l'implémentation** : la liste exhaustive des entités Doctrine (attributs, types PHP, types SQL, relations, index) est centralisée dans [`docs/modele-de-donnees.md`](modele-de-donnees.md). Cette section reste la source de vérité **sémantique** ; le fichier de modèle de données reflète la structure technique.

### 3.1 Projet

Un **projet** représente une initiative de la mairie (ex. "Refonte du site web", "Fibre dans les écoles", "Aménagement parc municipal"). C'est l'unité de regroupement des tâches.

#### Attributs

| Attribut | Type | Obligatoire | Description |
|---|---|---|---|
| `id` | UUID v7 | ✓ | Identifiant interne, immuable |
| `reference` | string (12) | ✓ (généré) | Référence lisible incrémentale annuelle, ex. `P-2026-014`, immuable. Préfixe `P-` pour les projets (cf. §8.14). Stockée sans le `#` ; affichée avec `#` |
| `slug` | string (255) | ✓ (généré) | Pour les URLs ; généré du titre, peut être édité par un admin |
| `title` | string (255) | ✓ | Titre du projet |
| `summary` | string (255) | ✗ | Résumé en une phrase, affiché dans les listes |
| `description` | text (markdown) | ✗ | Description complète |
| `status` | enum | ✓ | Voir cycle de vie ci-dessous |
| `visibility` | enum | ✓ | `public_interne` (tous authentifiés) ou `restricted` |
| `restrictedToGroups` | string[] | ✗ | Groupes Authentik autorisés si `visibility=restricted` (sinon ignoré) |
| `restrictedToWorkingGroups` | bool | ✓ | Toggle "réserver aux membres des groupes de travail associés" (cf. §3.11). Si `true`, seuls les membres d'au moins un `workingGroups[i]` (et le owner / coOwners) voient le projet. Indépendant de `visibility` ; les deux peuvent être combinés |
| `owner` | User | ✓ | Responsable du projet |
| `coOwners` | User[] | ✗ | Co-responsables, mêmes droits que le responsable sauf transfert d'ownership |
| `category` | Category | ✗ | Catégorie principale (taxonomie hiérarchique, cf. §3.6) |
| `labels` | string[] | ✗ | Étiquettes libres |
| `workingGroups` | WorkingGroup[] | ✗ | Groupes de travail associés (cf. §3.11) |
| `startDate` | date | ✗ | Date de début prévisionnelle |
| `dueDate` | date | ✗ | Date d'échéance prévisionnelle |
| `actualEndDate` | date | ✗ (renseignée à la transition `termine`) | Date de fin réelle |
| `archivedAt` | datetime | ✗ | Drapeau d'archivage (orthogonal au statut) |
| `createdAt` | datetime | ✓ | |
| `createdBy` | User | ✓ | Créateur initial |
| `updatedAt` | datetime | ✓ | Dernière modification |
| `updatedBy` | User | ✓ | Auteur de la dernière modification |

#### Visibilité

Trois leviers, combinables :

- **`visibility`**
  - `public_interne` (par défaut) : tous les utilisateurs authentifiés voient le projet.
  - `restricted` : seuls les membres d'au moins un des `restrictedToGroups` (groupes Authentik bruts) **plus** le responsable et les co-responsables voient/éditent. Utile pour les sujets RH ou confidentiels (groupes ad hoc).
- **`restrictedToWorkingGroups`** (toggle) : si activé, seuls les membres d'au moins un des `workingGroups` associés (plus owner et coOwners) voient le projet. Cas typique : un projet qui ne concerne **que** la commission Numérique → on coche le toggle, les autres ne le voient pas. Évite de devoir saisir manuellement `restrictedToGroups` quand le périmètre est déjà décrit par les groupes de travail.
- **`archivedAt`** : indépendant du statut et de la visibilité. Un projet archivé reste visible (s'il était visible) mais en lecture seule.

Les voters appliquent : `visible = (visibility=public_interne OR user∈restrictedToGroups) AND (NOT restrictedToWorkingGroups OR user∈anyOf(workingGroups))`. Le owner et les coOwners voient toujours leur projet, indépendamment de ces règles.

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
| `en_pause` | Suspendu temporairement (attente arbitrage, partenaire, etc.) | ✓ (champs métadonnées) | ✗ (les tâches ne peuvent pas changer de statut) |
| `termine` | Clôturé avec succès, lecture seule | Admin uniquement | ✗ |
| `annule` | Abandonné, lecture seule | Admin uniquement | ✗ |

#### Règles de transition

- `brouillon → actif` : "Activer". Vérifie que `owner`, `title` sont renseignés.
- `actif → en_pause` : "Mettre en pause". Demande un motif (texte libre, stocké dans le payload de l'événement `project.status_changed`).
- `en_pause → actif` : "Reprendre".
- `actif → termine` : "Clôturer". Bloque si une tâche du projet est dans un statut non terminal (`a_faire`, `en_cours`, `en_revue`, `bloquee`) **sauf** si l'utilisateur coche "ignorer les tâches restantes" (avec confirmation, audit trail).
- `actif | en_pause | brouillon → annule` : "Annuler". Demande un motif. Les tâches du projet basculent automatiquement en `annulee`.
- `termine → actif` ou `annule → actif` : interdit. Si réouverture nécessaire, créer un nouveau projet.

#### Droits par rôle (calculés dynamiquement, cf. §2)

> Rappel : `ROLE_CHEF_PROJET` = `owner` ou `coOwner` du projet ; `ROLE_ACTEUR` = membre d'un groupe de travail associé ; `ROLE_LECTEUR` = utilisateur authentifié sans appartenance ; `ROLE_ADMIN` = membre statique de `admin_spm`.

| Action | `ROLE_LECTEUR` | `ROLE_ACTEUR` | `ROLE_CHEF_PROJET` | `ROLE_ADMIN` |
|---|---|---|---|---|
| Voir un projet visible | ✓ | ✓ | ✓ | ✓ |
| Voir un projet `restricted` sans appartenir aux groupes | ✗ | ✗ | ✓ (si owner/coOwner) | ✓ |
| Créer un projet | ✗ | ✓ | n/a (devient owner → ROLE_CHEF_PROJET) | ✓ |
| Éditer le projet (champs métier, ajout/retrait coOwners, etc.) | ✗ | ✗ | ✓ | ✓ |
| Transférer l'ownership | ✗ | ✗ | ✓ | ✓ |
| Archiver / désarchiver | ✗ | ✗ | ✓ | ✓ |
| Clôturer (`actif → termine`) | ✗ | ✗ | ✓ | ✓ |
| Annuler (`actif/en_pause/brouillon → annule`) | ✗ | ✗ | ✓ | ✓ |
| Modifier un projet en `termine`/`annule` | ✗ | ✗ | ✓ | ✓ |
| Supprimer un projet | ✗ | ✗ | ✗ | ✓ |

> 💡 Pour créer un projet, il faut être membre d'au moins un groupe de travail (`ROLE_ACTEUR`). À la création, l'utilisateur devient automatiquement `owner` et donc `ROLE_CHEF_PROJET` sur ce projet. Cohérent : un utilisateur sans groupe de travail (lecteur) n'a pas vocation à porter un projet.

#### Actions

- Créer, éditer, archiver, désarchiver
- Transférer l'ownership (avec audit trail)
- Dupliquer (copie en `brouillon` sans tâches)
- Exporter PDF (Lot 5) / CSV (Lot 5)
- Suivre / ne plus suivre (Lot 4)

### 3.2 Tâche

Une **tâche** est une unité de travail. Elle peut être rattachée à un projet, à une **tâche parente** (sous-tâche), aux deux, ou à rien (autonome). Les tâches autonomes sont typiquement des signalements ponctuels qui ne s'inscrivent pas dans une initiative plus large (ex. un signalement citoyen "nid de poule devant chez moi" remonté via l'API du Lot 6, ou une demande isolée traitée à la volée par un agent).

Une tâche peut être assignée à un agent, et peut découler d'une demande externe (cf. Demandeur §3.10).

#### Attributs

| Attribut | Type | Obligatoire | Description |
|---|---|---|---|
| `id` | UUID v7 | ✓ | Identifiant interne, immuable |
| `reference` | string (12) | ✓ (généré) | Référence lisible, ex. `T-2026-0042`, immuable, incrémentale annuelle. Préfixe `T-` pour les tâches. Compteur séparé du compteur Project (cf. §8.14) |
| `title` | string (255) | ✓ | Titre |
| `description` | text (markdown) | ✗ | Détail de la tâche |
| `status` | enum | ✓ | Voir cycle de vie ci-dessous |
| `priority` | enum | ✓ | `basse` / `normale` (défaut) / `haute` / `critique` |
| `project` | Project | ✗ | Projet parent (optionnel — voir §"Tâches autonomes" ci-dessous) |
| `parentTask` | Task | ✗ | Tâche parente (sous-tâche). Cf. §"Sous-tâches" ci-dessous. Peut coexister avec `project` |
| `visibility` | enum | ✓ (uniquement si `project=null`) | `public_interne` ou `restricted` (sinon hérité du projet parent) |
| `restrictedToGroups` | string[] | ✗ | Groupes Authentik autorisés si `visibility=restricted` (et tâche autonome) |
| `assignee` | User | ✗ | Agent assigné |
| `requester` | Requester | ✗ | Demandeur externe (cf. §3.10) |
| `workingGroups` | WorkingGroup[] | ✗ | Hérités du projet par défaut **si** projet présent ; sinon saisis manuellement (cf. §3.11) |
| `labels` | string[] | ✗ | Étiquettes libres (peuvent être héritées du projet) |
| `dueDate` | date | ✗ | Échéance |
| `actualEndDate` | date | ✗ (renseignée à la transition `termine`) | Date de fin effective |
| `estimatedEffort` | enum | ✗ | T-shirt sizing : `XS` / `S` / `M` / `L` / `XL` (pas d'estimation en heures, trop fragile) |
| `blockedReason` | text | ✗ | Motif obligatoire si `status=bloquee` |
| `lastStatusChangeAt` | datetime | ✓ | Pour les indicateurs de stagnation |
| `publicLabel` | enum | ✗ | Mappage côté demandeur (cf. §3.10 : "Reçu" / "En traitement" / "Traité") — calculé automatiquement depuis `status` mais surchargeable au cas par cas |
| `source` | enum | ✓ | `manual` (création par un agent) / `citizen_api` (créé par l'API du Lot 6) / `import` — utile pour les statistiques et l'audit |
| `createdAt` | datetime | ✓ | |
| `createdBy` | User | ✗ (nullable si `source=citizen_api`) | Créateur agent ; vide si la tâche provient de l'API citoyenne |
| `updatedAt` | datetime | ✓ | |
| `updatedBy` | User | ✓ | |

#### Tâches autonomes (sans projet parent)

- **Cas d'usage** :
  - Signalement citoyen reçu via webservice (Lot 6) → tâche créée automatiquement, sans projet, avec `source=citizen_api`.
  - Demande isolée d'un habitant traitée par un agent qui ne veut pas créer un projet pour si peu.
  - Tâche personnelle d'un agent (suivi à faire) qui ne s'inscrit pas dans une initiative officielle.
- **Règles spécifiques** :
  - `visibility` est saisie directement sur la tâche (par défaut `public_interne`).
  - `workingGroups` est saisi manuellement (pas d'héritage possible).
  - Pas de cascade d'annulation (puisqu'il n'y a pas de projet parent).
  - Pas de contrainte "projet en pause" sur les transitions de statut.
- **Vue dédiée** : `/taches/autonomes` (filtre `project=null AND parentTask=null` sur la liste générale), accessible à tout utilisateur authentifié (les voters appliquent ensuite la visibilité de chaque tâche listée).
- **Promotion vers un projet** : un agent peut, plus tard, rattacher une tâche autonome à un projet existant (ou en créer un et y rattacher la tâche). L'opération est tracée dans l'audit (`task.attached_to_project`).

#### Sous-tâches (`parentTask`)

Une tâche peut être **fille d'une autre tâche** (relation `parentTask`, FK auto-référente sur `Task`). Cas typiques :

- Découper une tâche complexe en étapes (ex. "Refonte du formulaire de contact" → "Maquette", "Intégration", "Tests d'accessibilité").
- Regrouper des micro-tâches issues d'une même demande (ex. signalement citoyen "trottoir abîmé" → 2 sous-tâches : "Constat sur place", "Devis").

**Règles** :

- **Profondeur maximale** : 3 niveaux (parent → enfant → petit-enfant). Au-delà, refus avec message explicite. Limite arbitraire mais évite les arborescences ingérables ; ajustable plus tard si retours terrain.
- **Anti-cycle** : impossible d'assigner une descendante comme parente. Vérifié à la sauvegarde par un validateur `NoCycleValidator`.
- **Cohérence avec le projet** : si `parentTask` est définie ET que la parente a un `project`, la sous-tâche **doit** appartenir au même projet (forcé à la sauvegarde). Si la parente est autonome, la sous-tâche peut être autonome ou rattachée à un projet.
- **Visibilité héritée** : la sous-tâche hérite de la visibilité de sa parente (qui hérite du projet si présent). Pas de sous-tâche `restricted` sous une parente `public_interne` (interdit en v1, à reconsidérer si besoin légitime).
- **Cycle de vie** : indépendant de la parente. Une sous-tâche peut être `termine` alors que sa parente est `en_cours`. **Pas de cascade automatique** au changement de statut de la parente, sauf :
  - `parent → annulee` cascade en `annulee` sur les sous-tâches non terminales (événement `task.cascade_cancelled` sur chaque sous-tâche).
  - Pour clôturer une parente en `termine` : avertissement (pas blocage) si des sous-tâches sont non terminales, l'utilisateur peut confirmer en cochant "ignorer les sous-tâches restantes" (avec audit trail).
- **Détachement** : une sous-tâche peut être promue en tâche racine via `task.detached_from_parent` (event audit), idem pour rattachement (`task.attached_to_parent`).
- **Affichage** : sur la fiche tâche, la parente apparaît en **breadcrumb** (cliquable) ; les enfants sont listés dans un onglet "Sous-tâches" avec leur statut, assignée et progression.
- **Compteurs** : sur une tâche parente, affichage de `X/Y sous-tâches terminées` (utile pour la barre de progression).

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
- `en_cours | en_revue → termine` : "Clôturer". Renseigne `actualEndDate`. **L'assignée peut auto-valider sa propre revue** (pas de revue obligatoire en v1, choix tranché). Si on veut un mode "double validation" plus tard, on l'ajoutera comme paramètre par projet.
- `* → annulee` : "Annuler". Demande un motif. Statut terminal.
- Réouverture d'une tâche `termine` ou `annulee` : interdite. Créer une nouvelle tâche.

#### Contraintes de cycle

- **Si `project != null`** : une tâche ne peut pas changer de statut si son projet est en `en_pause`, `termine` ou `annule`. Si le projet bascule en `annule`, toutes ses tâches non terminales basculent automatiquement en `annulee` (avec un événement audit `task.cascade_cancelled`).
- **Si `project = null`** (tâche autonome) : aucune contrainte externe, le cycle de vie est libre.
- Une tâche en `bloquee` depuis plus de N jours apparaît dans le dashboard "alertes" (paramètre, défaut 14 jours), qu'elle ait un projet ou non.

#### Droits par rôle (calculés dynamiquement, cf. §2)

> Sur une tâche, `ROLE_CHEF_PROJET` = `owner`/`coOwner` du projet de la tâche, ou `createdBy` d'une tâche autonome. `ROLE_ACTEUR` = membre d'un groupe de travail associé à la tâche (ou à son projet si héritage). `assignee` est traité comme un cas particulier en plus de ces rôles.

| Action | `ROLE_LECTEUR` | `ROLE_ACTEUR` | `assignee` (de cette tâche) | `ROLE_CHEF_PROJET` | `ROLE_ADMIN` |
|---|---|---|---|---|---|
| Voir une tâche visible (incl. autonome) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Voir une tâche `restricted` sans appartenir aux groupes | ✗ | ✗ | ✓ | ✓ | ✓ |
| Créer une tâche dans un projet | ✗ | ✓ | n/a | ✓ | ✓ |
| Créer une tâche autonome (sans projet) | ✗ | ✓ | n/a | n/a (devient créateur → ROLE_CHEF_PROJET) | ✓ |
| Éditer les champs métier (titre, description, priorité, échéance, effort) | ✗ | ✗ | ✓ | ✓ | ✓ |
| Changer le statut (démarrer, bloquer, débloquer, envoyer en revue, clôturer) | ✗ | ✗ | ✓ | ✓ | ✓ |
| (Ré)assigner une tâche | ✗ | ✓ (s'auto-assigner uniquement) | ✓ (transférer à un autre acteur) | ✓ | ✓ |
| Annuler une tâche | ✗ | ✗ | ✓ | ✓ | ✓ |
| Rattacher / détacher un projet | ✗ | ✗ | ✗ | ✓ | ✓ |
| Créer une sous-tâche | ✗ | ✓ | ✓ | ✓ | ✓ |
| Supprimer une tâche (vraie suppression — sinon préférer `annulee`) | ✗ | ✗ | ✗ | ✗ | ✓ |

#### Actions

- Créer (avec ou sans projet), éditer, supprimer (admin uniquement, déclenchée → bascule plutôt en `annulee`)
- Changer le statut, l'assigné, la priorité
- Associer / dissocier un demandeur
- Surcharger les groupes de travail (par défaut hérités du projet)
- **Rattacher à un projet** (pour les tâches autonomes) ou **détacher d'un projet** (rendre autonome) — déclenche un événement audit
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

- Stockée localement dans un volume Docker (`/var/uploads`) en v1. Anticipation d'une **GED externe à brancher plus tard** (cf. §6 hors scope) : on isole le stockage derrière une interface `AttachmentStorage` pour pouvoir basculer sans réécrire le métier.
- **Limites internes (Project/Task)** : 25 Mo par fichier, 10 fichiers max par objet.
- **Limites portail demandeur** (cf. §3.10) : photos uniquement (jpg/png/heic/webp), 5 Mo max après compression serveur, 3 fichiers par commentaire.
- Types autorisés en interne : PDF, images, bureautique (docx, xlsx, odt, ods), archives (zip, 7z). Pas d'exécutables.
- Scan antivirus (ClamAV) optionnel en v1 sur les uploads issus du portail demandeur (Lot 6) ; recommandé sur les uploads internes en prod.

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

#### Avatar

Trois sources possibles, résolues dans cet ordre par un service `UserAvatarResolver` :

1. **Upload local** (`avatarPath` sur `User`) — **prioritaire si renseigné**. L'utilisateur peut uploader sa propre photo depuis `/profile`. Stocké via l'interface `AttachmentStorage` (cf. §3.5), formats jpg/png/webp, 2 Mo max, redimensionnement serveur à 512 × 512 px.
2. **Authentik (cache local)** — récupéré au login depuis le claim `picture` du userinfo OIDC (si l'utilisateur a une photo dans son profil Authentik), puis **téléchargé et caché localement** via `AttachmentStorage`. Affiché ensuite depuis le cache (pas de dépendance runtime à l'uptime ou au domaine d'Authentik, et aucune fuite cross-origin de session). Champs sur `User` : `authentikAvatarSourceUrl` (URL d'origine, sert à détecter un changement), `authentikAvatarPath` (chemin local), `authentikAvatarFetchedAt` (timestamp du dernier téléchargement). Re-téléchargement déclenché au login si l'URL source change OU si `authentikAvatarFetchedAt` date de plus de 24 h. Téléchargement borné : timeout 5 s, taille max 2 Mo, content-type `image/*` vérifié, redimensionnement serveur à 512 × 512 px ; échec silencieux qui fait basculer sur la source suivante.
3. **Gravatar** — fallback automatique calculé depuis l'e-mail (hash SHA-256, conforme à la nouvelle API Gravatar). URL : `https://gravatar.com/avatar/{hash}?d=404&s=512`. Le `d=404` permet de détecter l'absence côté Gravatar et de basculer sur le fallback final.
4. **Fallback final** : initiales du `displayName` (ex. "JM" pour Jean Martin) sur fond coloré dérivé de l'`authentikId` (couleur stable par utilisateur). Rendu côté serveur en SVG inline pour éviter la dépendance externe.

Helper Twig `{{ user|avatar(size=64) }}` qui encapsule la logique. Le service prend en compte une **préférence utilisateur** `avatarSource` (`auto` par défaut = priorité ci-dessus, `local`, `authentik`, `gravatar`, `initials`) pour permettre à un utilisateur de forcer l'une des sources.

> 🔒 **Privacy** : Gravatar n'est interrogé que si l'utilisateur n'a pas désactivé cette source dans `/profile` (toggle "Autoriser le fallback Gravatar pour mon avatar"). Par défaut activé pour les comptes nouvellement créés mais désactivable. La requête vers gravatar.com fuite l'e-mail (sous forme de hash) — affiché clairement dans la page profil.

### 3.9 Événement d'audit (audit log)

Trace immuable de toutes les actions importantes effectuées dans l'application. **Pas un log technique** (qui va dans `var/log`), mais un journal métier consultable par les admins.

- **Propriétés** : `id`, `occurredAt`, `category` (`security` / `project` / `task` / `user` / `requester` / `working_group` / `admin` / `comment` / `attachment` / `notification` / `system` / `api`), `action` (slug : `user.login`, `project.created`, `task.assigned`…), `actor` (User, nullable pour événements système), `subjectType` + `subjectId` (objet concerné, nullable), `payload` (JSON contextualisé, ex. ancien et nouveau statut), `ipAddress`, `userAgent`.
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
| `project.working_group_linked` / `project.working_group_unlinked` | Lien d'un groupe de travail | `{ workingGroupId }` |
| `project.cascade_cancelled_tasks` | Tâches automatiquement annulées suite à `project.annule` | `{ taskCount }` |

**Catégorie `task`** (Lot 1)

| Slug | Quand | Payload |
|---|---|---|
| `task.created` | Création | `{ title, projectId?, requesterId?, source }` |
| `task.updated` | Édition | `{ changes: {...} }` |
| `task.status_changed` | Transition de statut | `{ from, to, reason? }` |
| `task.blocked` | Passage en `bloquee` | `{ reason }` |
| `task.unblocked` | Sortie de `bloquee` | `{}` |
| `task.assigned` | (Re)assignation | `{ from, to }` |
| `task.priority_changed` | Changement de priorité | `{ from, to }` |
| `task.requester_linked` | Demandeur associé à la tâche | `{ requesterId }` |
| `task.requester_unlinked` | Demandeur dissocié | `{ requesterId }` |
| `task.working_groups_changed` | Modification des groupes de travail associés | `{ added: [...], removed: [...] }` |
| `task.attached_to_project` | Tâche autonome rattachée à un projet | `{ projectId }` |
| `task.detached_from_project` | Tâche rattachée à un projet rendue autonome | `{ previousProjectId }` |
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

**Catégorie `working_group`** (Lot 1)

| Slug | Quand | Payload |
|---|---|---|
| `working_group.created` | Création d'un groupe de travail | `{ name, slug }` |
| `working_group.updated` | Édition (hors mapping Authentik) | `{ changes: {...} }` |
| `working_group.archived` / `working_group.unarchived` | Archivage | `{}` |
| `working_group.mapping_changed` | Modification du `authentikGroup` mappé | `{ from, to }` |

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
  - **`firstName` et `lastName` sont obligatoires** (décision tranchée). Pas de signalement anonyme en v1 ; ce besoin pourra être reconsidéré dans un lot ultérieur si la mairie le souhaite.
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

Permet au demandeur, sans compte ni mot de passe, de consulter et **commenter** sa demande.

- **Mécanisme** : à la création du demandeur (ou à la première association à une tâche), génération d'un **jeton aléatoire** (32 octets, base62, ~43 caractères). Stocké hashé en base, l'URL contient la version claire.
- **URL type** : `https://projets.mairie.example.fr/suivi/{jeton}`. La page liste les tâches du demandeur, leur statut (libellé simplifié — voir ci-dessous), l'historique public, et permet d'ajouter un commentaire.
- **Visibilité des commentaires** : seuls ceux explicitement marqués **public** par un agent (case à cocher "visible par le demandeur") sont visibles. Les commentaires internes restent cachés.
- **Statuts affichés au demandeur (libellés simplifiés mappés)** :
  - `a_faire`, `en_cours`, `bloquee` → "**Reçu**" puis "**En traitement**"
  - `en_revue` → "**En traitement**"
  - `termine` → "**Traité**"
  - `annulee` → "**Sans suite**"

  Mapping fixe en v1, pas de surcharge possible par tâche (décision tranchée). Si un cas particulier le justifie plus tard, on ajoutera la surcharge `publicLabel` au cas par cas.
- **Commentaires depuis le portail** : autorisés. Notification "nouveau commentaire demandeur" envoyée à l'assignée et à l'owner du projet (si présent). Modération a posteriori (un agent peut masquer un commentaire abusif).
- **Pièces jointes depuis le portail** : **photos uniquement** (jpg, png, heic, webp), max 5 Mo par fichier après compression serveur (resize si dimension > 2048 px), 3 fichiers par commentaire. Pas de PDF ni autres formats. Scan antivirus ClamAV obligatoire avant stockage.
- **Durée de validité** : tant qu'au moins une tâche du demandeur n'est pas clôturée, le jeton reste actif. À clôture de la dernière tâche, le jeton expire 30 jours après.
- **Révocation** : un agent peut révoquer manuellement le jeton (régénération possible).
- **Sécurité** :
  - Rate limiting strict sur ces routes (Symfony RateLimiter + Redis), notamment sur les commentaires (5 commentaires max / jour / jeton).
  - Lien jeton **HTTPS uniquement**, jamais en clair dans les logs.
  - Jeton à entropie élevée, comparaison `hash_equals` côté serveur.
  - Pas d'auto-complétion / cache navigateur (`Cache-Control: no-store`).

### 3.11 Groupe de travail (WorkingGroup)

Un **groupe de travail** est la projection locale d'un groupe Authentik dans l'application. C'est l'entité pivot du modèle de droits : l'appartenance à un groupe de travail (issue du claim OIDC `groups` au login) conditionne le calcul du rôle `ROLE_ACTEUR` sur les Project / Task associés (cf. §2).

#### Population automatique au login

L'application **ne dépend pas de l'API admin Authentik**. La table `working_groups` se peuple toute seule au fil des connexions :

1. Au callback OIDC, l'app lit le claim `groups` du token (liste de noms de groupes Authentik).
2. Pour chaque nom de groupe :
   - S'il n'existe **pas** encore en base → création d'une nouvelle ligne `WorkingGroup` avec `visible=false` (défaut), `label` initialisé à partir du nom machine (humanisation simple : `commission-numerique` → "Commission Numerique"), `firstSeenAt = now()`.
   - S'il existe déjà → `lastSeenAt = now()`.
3. La liste des groupes de travail visibles dans les sélecteurs Project/Task est filtrée sur `visible=true AND archivedAt IS NULL`. Les groupes nouvellement créés sont donc invisibles tant que l'admin ne les active pas explicitement.

> 💡 Conséquence : tant que personne ayant le groupe X dans Authentik ne s'est connecté à l'app, le groupe X n'existe pas dans l'app. C'est volontaire — on évite de polluer la base avec des groupes "fantômes".

#### Attributs

| Attribut | Type | Obligatoire | Description |
|---|---|---|---|
| `id` | UUID v7 | ✓ | |
| `authentikName` | string (255) | ✓ | Nom machine du groupe côté Authentik (clé de réconciliation, unique). Read-only côté admin |
| `label` | string (128) | ✓ | Libellé affiché ("Commission Numérique"). Initialisé à la création par humanisation de `authentikName`, **éditable par l'admin** |
| `slug` | string (64) | ✓ | Slug pour URLs / filtres, généré à partir de `label` (regénérable si `label` change) |
| `description` | text | ✗ | Présentation, périmètre, missions. Éditable par l'admin |
| `color` | string (hex) | ✗ | Pour les badges. Éditable par l'admin |
| `icon` | string | ✗ | Emoji ou nom d'icône. Éditable par l'admin |
| `visible` | bool | ✓ | Toggle "Visible dans les sélecteurs Project/Task". Défaut `false`. Éditable par l'admin |
| `position` | int | ✓ | Ordre d'affichage (parmi les visibles). Défaut `0` |
| `firstSeenAt` | datetime | ✓ | Premier login observé contenant ce groupe |
| `lastSeenAt` | datetime | ✓ | Dernier login observé contenant ce groupe |
| `archivedAt` | datetime | ✗ | Pour ne pas perdre l'historique d'un groupe dissous (masque automatiquement des sélecteurs) |
| `createdAt` / `updatedAt` | datetime | ✓ | |
| `updatedBy` | User | ✗ | Dernier admin ayant édité le groupe (null si seule la création auto a eu lieu) |

> Pas de `createdBy` : la ligne est créée automatiquement par le subscriber de login, pas par un utilisateur identifié.

#### Calcul du nombre de membres

Le nombre de membres d'un groupe de travail s'obtient en interrogeant la projection locale des utilisateurs : `User.groupsSnapshot` (le claim `groups` capté au dernier login) contient le nom du groupe.

```sql
SELECT COUNT(*) FROM users WHERE :authentikName = ANY(groupsSnapshot) AND disabledAt IS NULL
```

Calculé à la volée (pas de cache) sur l'écran admin. Limite assumée : un utilisateur qui n'a jamais connecté l'app n'est pas compté, et un changement d'appartenance côté Authentik n'est répercuté qu'au prochain login.

#### Écran d'administration

`/admin/groupes-de-travail` (réservé à `ROLE_ADMIN`) :

| Colonne | Source | Modifiable |
|---|---|---|
| **Nom machine** | `authentikName` | ✗ (côté Authentik uniquement) |
| **Label** | `label` | ✓ |
| **Membres** | calculé `COUNT(users WHERE …)` | ✗ |
| **Visible** | `visible` | ✓ (toggle direct) |
| **Première / dernière connexion observée** | `firstSeenAt` / `lastSeenAt` | ✗ |
| **Action** | bouton "Modifier" | → édite `label`, `description`, `color`, `icon`, `visible`, `position`, `archivedAt` |

Tri par défaut : `lastSeenAt DESC` (les groupes actifs remontent). Filtres : `visible only`, `archived`, recherche par `authentikName` ou `label`.

#### Liens avec Project et Task

- **Project** : relation many-to-many `Project ↔ WorkingGroup`. Un projet peut être co-piloté par plusieurs groupes de travail (ex. un projet de skate-park concerne Jeunesse + Services Techniques). Champ optionnel.
- **Task** : relation many-to-many `Task ↔ WorkingGroup`. À la création d'une tâche, les groupes de travail du projet parent sont **hérités par défaut** mais l'utilisateur peut les modifier.
- **Sélecteurs** : seuls les groupes `visible=true AND archivedAt IS NULL` sont proposés à la sélection. Si un projet a un groupe qui devient ensuite invisible/archivé, l'association reste persistée mais le groupe est affiché en grisé.

#### Filtrage et navigation

- Filtre "mon/mes groupe(s) de travail" sur les listes Projects et Tasks (pré-coché si l'utilisateur n'appartient qu'à un seul).
- Vue dédiée par groupe : `/groupes-de-travail/<slug>` listant projets + tâches en cours, avec indicateurs (nb projets actifs, tâches en retard…). Accessible uniquement pour les groupes `visible`.
- Affichage des badges sur les fiches Project et Task (couleur + label).

#### Droits

- Voir la liste des groupes de travail visibles : tous les utilisateurs.
- Voir l'écran d'admin (tous les groupes y compris non visibles), basculer la visibilité, éditer label/description/icon/color/position, archiver : `ROLE_ADMIN` uniquement.
- Supprimer un groupe : **interdit** (un groupe Authentik existe ou non, on ne peut pas le supprimer côté app sans casser la réconciliation au prochain login). Utiliser l'archivage.

#### Cas particuliers à anticiper

- **Groupe disparu côté Authentik** : si un groupe n'a pas été observé depuis longtemps (ex. > 90 jours), un indicateur "potentiellement dissous" apparaît dans l'admin. L'admin peut alors archiver. La ligne reste pour préserver l'historique des associations Project/Task passées.
- **Renommage côté Authentik** : si le nom machine change côté Authentik, l'app crée une nouvelle ligne `WorkingGroup` (l'ancienne tombe en désuétude — pas vue récemment). Pas de réconciliation auto. À documenter : renommer un groupe Authentik = recréer le mapping côté app et migrer les associations à la main.
- **Plusieurs apps mariant les mêmes groupes** : aucun impact, on lit juste le claim `groups`. Les groupes que cette app marque comme `visible` ne le sont qu'à l'échelle de **cette** instance.

#### Calcul de l'appartenance

Au login OIDC, on récupère la liste des groupes Authentik de l'utilisateur (claim `groups`). Pour chaque groupe de travail actif et mappé, on vérifie si son `authentikGroup` figure dans la liste :

```
userWorkingGroups = [
  wg for wg in WorkingGroup.findActive()
  if wg.authentikGroup in user.authentikGroups
]
```

Cette liste est stockée en cache Redis avec TTL aligné sur la session, exposée dans `User::getWorkingGroups()`.

#### Liens avec Project et Task

- **Project** : relation many-to-many `Project ↔ WorkingGroup`. Un projet peut être co-piloté par plusieurs groupes de travail (ex. un projet de skate-park concerne Jeunesse + Services Techniques). Champ optionnel.
- **Task** : relation many-to-many `Task ↔ WorkingGroup`. À la création d'une tâche, les groupes de travail du projet parent sont **hérités par défaut** mais l'utilisateur peut les modifier.

#### Filtrage et navigation

- Filtre "mon/mes groupe(s) de travail" sur les listes Projects et Tasks (pré-coché si l'utilisateur n'appartient qu'à un seul).
- Vue dédiée par groupe : `/groupes-de-travail/<slug>` listant projets + tâches en cours, avec indicateurs (nb projets actifs, tâches en retard…).
- Affichage des badges sur les fiches Project et Task (couleur + nom).

#### Droits

- Voir la liste des groupes de travail : tous les utilisateurs.
- Créer / éditer / archiver un groupe de travail, modifier son mapping Authentik : `ROLE_ADMIN` uniquement.
- Aucune restriction de visibilité Project/Task basée sur l'appartenance par défaut (les groupes de travail sont **organisationnels**, pas un mécanisme de contrôle d'accès — pour ça, utiliser `visibility=restricted` sur le Project).

#### Cas particuliers à anticiper

- **Mapping orphelin** : le groupe Authentik mappé n'existe plus côté Authentik → l'admin voit un avertissement dans la fiche (groupe inconnu, plus aucun utilisateur identifié).
- **Renommage du groupe Authentik** : casse le mapping. À documenter (mettre à jour le champ `authentikGroup` après).
- **Groupe de travail sans groupe Authentik mappé** : autorisé, sert simplement de tag organisationnel sans appartenance automatique calculée.

### 3.12 Menu d'outils externes (lanceur d'applications)

Dans la barre de navigation principale, en plus du menu de l'outil, un **menu déroulant** affiche des raccourcis vers d'autres outils internes de la mairie (genre app launcher type "grille Google Apps"). Permet de circuler facilement entre les outils auto-hébergés.

- Configuré côté **administration** (et/ou via fichier de config / `.env`, à trancher).
- Chaque entrée : libellé, URL, icône (image ou emoji ou lettre), description courte (tooltip). Visible par tout utilisateur authentifié — pas de restriction par rôle (décision tranchée).
- Stockage : entité `ExternalLink` simple (`label`, `url`, `icon`, `description`, `position`, `enabled`).
- UI : icône "grille" dans le header → dropdown ou panneau plein-écran sur mobile. Liens en target `_blank` avec `rel="noopener"`.
- Pas d'authentification SSO transparente attendue côté app : on suppose que l'utilisateur est authentifié sur les outils externes via Authentik (le SSO étant déjà en place pour eux aussi).
- 🟡 **À décider** : configuration via interface admin (plus pratique) ou via `.env` (plus simple pour la v0). Recommandation : **interface admin** dès le Lot 0 (entité + CRUD), reste léger à coder.

### 3.13 Références croisées (cross-references)

Dans les **descriptions** (Project, Task) et les **commentaires** (Lot 4), les utilisateurs peuvent référencer d'autres projets/tâches en saisissant leur référence. À la sauvegarde du contenu markdown, l'app **détecte automatiquement** ces références, les transforme en liens cliquables, et **persiste un index de backlinks** (relation inverse "référencé dans").

#### Format

- **Référence courte** : `#P-YYYY-NNN` (projet) ou `#T-YYYY-NNN` (tâche), ex. `#P-2026-014` ou `#T-2026-042`.
- **Référence longue avec slug** (optionnel, style GitHub) : `#P-YYYY-NNN-slug-libre`, ex. `#P-2026-014-refonte-site`. Le slug est **purement décoratif** : il n'est ni vérifié ni utilisé pour la résolution. L'app résout uniquement sur `P-YYYY-NNN` ou `T-YYYY-NNN`.
- Les compteurs Project et Task sont **séparés** (séquences Postgres dédiées), le préfixe `P-`/`T-` lève l'ambiguïté dans les textes (cf. décision §8.14).

Exemple complet dans un commentaire :

```markdown
Cette tâche dépend de #T-2026-019 et concerne le projet #P-2026-014.

À la suite du chantier #P-2026-014-refonte-site, on va devoir traiter
les sous-tâches #T-2026-042-formulaire-contact et #T-2026-043.

Pour le contexte, voir aussi le projet annulé #P-2025-007.
```

#### Détection

Service `CrossReferenceParser` qui :

1. Tokenise le markdown (en respectant les blocs de code et les liens existants — pas de transformation à l'intérieur de `\`\`\`` ou de `[texte](url)`).
2. Extrait les références matchant la regex `#(?P<type>[PT])-(?P<year>\d{4})-(?P<num>\d{1,5})(?:-[a-z0-9-]+)?\b` (le slug suffixe est capturé mais ignoré pour la résolution).
3. Résout chaque référence vers un Project (si `P-`) ou une Task (si `T-`). Si la référence n'existe pas, le texte est laissé tel quel (pas de lien cassé, pas d'entrée dans `cross_references`).
4. Remplace dans le rendu HTML par un `<a>` avec :
   - URL : `/projets/<slug>` ou `/taches/<id>` (selon le type)
   - Texte : la référence telle qu'écrite (avec slug si présent)
   - Tooltip : `[Statut] Titre — Assignée?`
   - Classe CSS : `cross-ref cross-ref-project` ou `cross-ref-task`, plus `cross-ref-status-<status>` pour styler en fonction du statut (ex. terminé en barré).

#### Backlinks (index inverse)

À chaque sauvegarde d'un contenu (description ou commentaire), un subscriber Doctrine met à jour la table `cross_references` :

- `sourceType` + `sourceId` : l'objet qui contient la référence (Task/Project/Comment)
- `targetType` + `targetId` : l'objet référencé (Task/Project)
- `createdAt`

Sur la fiche d'un Project ou d'une Task, un onglet/bloc **"Référencé dans"** liste les sources qui pointent vers l'objet courant. Indexé pour requête en O(1) côté target.

Suppression / édition : à chaque save, on diff l'ancienne et la nouvelle liste de références, on insère/supprime dans `cross_references` en conséquence.

#### Autocomplete (à la frappe)

Composant Stimulus inspiré de l'expérience GitHub :

- Déclencheur : saisie du caractère `#` dans une textarea markdown (description, commentaire).
- Endpoint backend : `GET /api/internal/references/search?q=<query>&type=<P|T|both>&limit=10` qui retourne JSON : `[{ ref, type, title, status, url }, ...]`.
- Heuristique sur `q` :
  - `P-2026-` → restreint aux projets de l'année
  - `T-` → restreint aux tâches
  - texte libre → recherche par titre (full-text Postgres `tsvector`)
- Priorisée par récence (dernière modification) puis pertinence titre.
- Sécurité : applique les voters — seuls les objets visibles par l'utilisateur courant remontent.
- Rate limit : 30 requêtes / minute / utilisateur (Symfony RateLimiter + Redis), cache navigateur 5 secondes.
- À la sélection, insère `#P-YYYY-NNN-slug` ou `#T-YYYY-NNN-slug` dans le textarea (slug du titre kebab-cased, tronqué à 40 car).

#### Audit

- `cross_reference.created` (Lot 4) : `{ source: {type, id}, target: {type, id} }`
- `cross_reference.removed` (Lot 4) : idem

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

#### Filtrage d'accès à l'application (defense in depth)

Décision tranchée : on filtre **côté Authentik ET côté app** (les deux), pour limiter le risque qu'une mauvaise configuration laisse passer un utilisateur non autorisé.

- **Côté Authentik** : Policy Binding sur l'application qui restreint le login aux membres d'un ou plusieurs groupes (cf. `docs/authentik.md` §1.5). C'est la ligne de défense principale — un non-membre ne peut même pas s'authentifier.
- **Côté app** : variable d'environnement `OIDC_REQUIRED_GROUPS` (liste séparée par virgules). Au callback OIDC, l'app vérifie qu'au moins un des groupes Authentik de l'utilisateur figure dans cette liste. Si non, on rejette le login avec une page "Accès non autorisé" claire et un événement audit `security.access_denied`.
- L'utilisateur dont le compte est désactivé côté Authentik n'arrive plus à s'authentifier ; sa projection locale (`User`) est conservée pour préserver l'historique mais marquée `disabledAt`.

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

- Portail public citoyen complet (le portail demandeur via jeton est inclus à partir du Lot 6, mais ce n'est pas un site public ouvert)
- Application mobile native (l'application web est responsive mobile-first)
- Multi-tenant / multi-mairies
- Signature électronique de documents (parapheur)
- Vidéoconférence intégrée
- Diagrammes de Gantt complexes (on se contente d'une frise simple)
- Export ICS du calendrier (peut venir en v1.x si demandé)
- **GED externe** : à anticiper architecturalement (interface `AttachmentStorage` isolée pour basculer vers Nextcloud / Paperless / Alfresco / autre plus tard sans réécrire le métier). Pas d'implémentation en v1.

## 7. Anticipations pour les évolutions futures

- **API REST citoyenne** : tous les services applicatifs sont conçus avec des DTOs typés, pas de dépendance à `Request`/`Session`. Ajout d'API Platform sur les ressources concernées.
- **Multi-mairie** : le modèle de données n'inclut pas de notion de "tenant" en v1, mais on évite les singletons globaux qui rendraient l'évolution douloureuse.
- **Webhooks sortants** : prévoir une table `webhook_subscription` dès qu'on en aura besoin pour notifier l'app citoyenne.
- **GED externe** : interface `AttachmentStorage` (FileSystemStorage en v1, GedStorage à brancher plus tard). Aucune ré-écriture du domaine nécessaire pour migrer.
- **Surcharge des libellés publics** (`publicLabel` par tâche) : code-prêt mais non exposé en v1. À activer si la mairie remonte un cas d'usage légitime.
- **Mode "double validation" sur la clôture des tâches** : paramètre `requiresReview` au niveau du projet, pas implémenté en v1, à ajouter ultérieurement si demandé.

## 8. Décisions tranchées

Toutes les questions ouvertes initiales ont été tranchées avec le PO. Les décisions sont reportées dans les sections concernées ; le récapitulatif est conservé ici à des fins de traçabilité.

| # | Sujet | Décision |
|---|---|---|
| 1 | Tâches sans projet parent | **Autorisées** (tâches autonomes, cf. §3.2) |
| 2 | Framework CSS | **Tailwind CSS** |
| 3 | Limites pièces jointes (interne) | **25 Mo / fichier, 10 fichiers max** par objet |
| 4 | Rétention | **Logs 90 j**, **audit log 3 ans** |
| 5 | Groupes Authentik utilisés par l'app | **Un seul groupe applicatif statique** : `admin_spm` (configurable via `OIDC_ADMIN_GROUP`). Les autres rôles sont calculés dynamiquement (cf. §2). Les **groupes de travail** (commissions, services, etc.) réutilisent les groupes Authentik métier existants — l'admin coche ceux qui doivent être visibles dans l'app |
| 6 | Intégrations externes | **GED à anticiper** (interface `AttachmentStorage` isolée, implémentation FileSystem en v1). Pas de LDAP, pas de parapheur en v1 |
| 7 | Visibilité par défaut entre agents | **Tout est visible par défaut** (transparence interne). Pour la confidentialité, utiliser `visibility=restricted` ou `restrictedToWorkingGroups` |
| 8 | Demandeur — champs obligatoires | **firstName + lastName obligatoires**, plus au moins un de `email`/`phone` |
| 9 | Portail demandeur — commentaire | **Autorisé** (avec rate limit, modération a posteriori) |
| 10 | Portail demandeur — pièces jointes | **Photos uniquement** (jpg/png/heic/webp), 5 Mo max après compression, 3 fichiers par commentaire, scan ClamAV |
| 11 | Statuts visibles par le demandeur | **Libellés simplifiés mappés** : Reçu / En traitement / Traité / Sans suite. Mapping fixe en v1 (pas de surcharge) |
| 12 | Revue obligatoire avant clôture | **Non** : l'assignée peut auto-valider sa propre revue. Mode "double validation" prévu en évolution future (paramètre par projet) |
| 13 | Estimation d'effort | **T-shirt sizing** (XS / S / M / L / XL) |
| 14 | Format de référence | **`#P-YYYY-NNN`** pour les projets, **`#T-YYYY-NNN`** pour les tâches. Compteurs **séparés** : séquences Postgres `project_reference_seq_<year>` et `task_reference_seq_<year>`. Le préfixe `P-`/`T-` lève l'ambiguïté lors du parsing des références croisées dans les textes (§3.13). Slug optionnel en suffixe pour la lisibilité (`#P-2026-014-refonte-site`), non vérifié à la résolution |
| 15 | Découverte des groupes de travail | **Population automatique au login** : à chaque connexion OIDC, les nouveaux groupes du claim `groups` créent une ligne `WorkingGroup` avec `visible=false`. L'admin active la visibilité au cas par cas dans l'admin. Pas de dépendance API admin Authentik (révision : les entités `AuthentikGroup` + `WorkingGroup` séparées sont fusionnées en une seule, cf. §3.11) |
| 16 | Visibilité par groupe de travail | **Hybride** : par défaut organisationnel uniquement, mais toggle `restrictedToWorkingGroups` sur Project pour réserver la visibilité aux membres des groupes de travail associés |
| Bonus | Filtrage d'accès à l'application | **Côté Authentik (Policy Binding) + côté app (`OIDC_REQUIRED_GROUPS`)** — defense in depth |

### Nouvelles questions ouvertes (au fil de l'eau)

> Ce paragraphe se remplira avec les futures itérations.

- *(rien pour le moment)*
