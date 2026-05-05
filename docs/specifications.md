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

## 4. Spécifications fonctionnelles par écran (à compléter)

> 🟡 À remplir au fil des itérations. Pour chaque écran : objectif, données affichées, actions, règles de sécurité.

- [ ] Écran d'accueil / dashboard
- [ ] Liste des projets (filtres, tri, recherche)
- [ ] Fiche projet (onglets : tâches, jalons, fichiers, activité)
- [ ] Liste des tâches (vue tableau + vue Kanban)
- [ ] Fiche tâche
- [ ] Mes tâches (vue personnelle)
- [ ] Calendrier (échéances et jalons)
- [ ] Préférences utilisateur
- [ ] Administration (catégories, paramètres globaux)

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
