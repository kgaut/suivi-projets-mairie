# Roadmap

> Document vivant. Idées non priorisées en bas, regroupées en lots dans la section principale. Chaque lot livré donnera lieu à un **tag git annoté** (`v0.X.0`).

## Convention

- Un **lot** = un ensemble cohérent de tâches livrables ensemble = **un tag** sur `main`.
- Une **tâche** = une issue GitHub = une branche `feat/<n°>-<slug>` = une PR.
- Statut des lots : `📅 prévu` / `🚧 en cours` / `✅ livré`.

## Lots planifiés

### Lot 0 — Fondations · `v0.1.0` · 📅 prévu

Squelette technique opérationnel, sans fonctionnalité métier.

- [ ] `composer create-project symfony/skeleton` + structure `src/` (Controller / Application / Domain / Infrastructure / Security)
- [ ] Dockerfile FrankenPHP + `docker-compose.dev.yml` + `docker-compose.prod.yml`
- [ ] `Makefile` (install, migrate, test, stan, cs, shell, reset)
- [ ] Configuration Doctrine + Postgres + premières migrations vides
- [ ] Configuration Redis (cache + sessions + Messenger)
- [ ] Intégration Authentik OIDC + voter de base + page `/profile` listant les groupes Authentik de l'utilisateur connecté
- [ ] Layout Twig de base (header avec menu utilisateur, footer, page d'accueil placeholder)
- [ ] Symfony UX Turbo + Stimulus en place + composant Hello World
- [ ] CI GitHub Actions (lint + tests + phpstan + composer audit)
- [ ] CI GitLab miroir
- [ ] Build + push image GHCR sur tag
- [ ] Doc d'install à jour (`docs/local-dev.md`, `docs/deploiement.md`, `docs/authentik.md`)

**Critère de fin** : un nouvel arrivant clone le repo, lance `make install`, se connecte via Authentik, voit ses groupes sur `/profile`. La CI est verte. Une image taguée `v0.1.0` est publiée sur GHCR.

### Lot 1 — Projets et tâches · `v0.2.0` · 📅 prévu

CRUD de base avec assignation et statuts.

- [ ] Entité `Project` + migration + fixtures de dev
- [ ] Entité `Task` + migration + fixtures de dev
- [ ] CRUD Project (liste, fiche, créer, éditer, archiver)
- [ ] CRUD Task (liste filtrée par projet, fiche, créer, éditer)
- [ ] Statuts (workflow Symfony) sur Project et Task
- [ ] Assignation d'une tâche à un utilisateur
- [ ] Voters : qui peut éditer quoi
- [ ] Vue "Mes tâches"
- [ ] Tests fonctionnels du parcours complet

### Lot 2 — Vue d'ensemble · `v0.3.0` · 📅 prévu

Visualisation et recherche.

- [ ] Vue Kanban des tâches d'un projet (drag & drop via Stimulus + Turbo)
- [ ] Recherche full-text Postgres (titre, description) sur projets et tâches
- [ ] Filtres avancés (statut, assigné, échéance, étiquettes)
- [ ] Dashboard d'accueil (mes tâches en retard, projets que je suis, activité récente)
- [ ] Système d'étiquettes libres
- [ ] Catégories hiérarchiques administrées

### Lot 3 — Collaboration · `v0.4.0` · 📅 prévu

Échanges autour des projets et tâches.

- [ ] Commentaires markdown sur Project et Task
- [ ] Mentions `@utilisateur` (autocomplete)
- [ ] Pièces jointes (upload, prévisualisation, suppression)
- [ ] Notifications in-app (badge + dropdown)
- [ ] Notifications e-mail (Mailer + Messenger async)
- [ ] Préférences utilisateur (toggle e-mail / in-app)
- [ ] Système "suivre un projet/tâche"

### Lot 4 — Pilotage · `v0.5.0` · 📅 prévu

Outils de suivi macro.

- [ ] Jalons sur les projets + frise chronologique
- [ ] Vue calendrier (échéances + jalons) avec FullCalendar ou équivalent léger
- [ ] Export CSV des projets et tâches
- [ ] Export PDF d'une fiche projet (rapport complet)
- [ ] Tableau de bord "vue élu" (projets actifs, jalons à venir, alertes)
- [ ] Rappels d'échéance (J-3, J-1) par e-mail

### Lot 5 — Préparation API citoyenne · `v0.6.0` · 📅 prévu

Première brique pour la future application citoyenne de signalements.

- [ ] Mise en place d'API Platform sur les ressources publiques (Project en lecture)
- [ ] Authentification API par token (clés générées par les admins)
- [ ] Endpoint `POST /api/signalements` (qui devient une Task d'un projet "Signalements citoyens")
- [ ] Documentation OpenAPI
- [ ] Tests d'intégration API
- [ ] Rate limiting (Symfony RateLimiter + Redis)

### Lot 6+ — À définir

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
