# CLAUDE.md

Pointer pour Claude Code. La doc détaillée vit dans `docs/`.

## État du projet

Phase de cadrage — pas de code Symfony encore. Specs et roadmap en relecture
sur la PR #1. Le Lot 0 (fondations Symfony + Docker + CI + OIDC) sera attaqué
une fois les specs stabilisées.

## Avant toute modification

Lire dans cet ordre :

1. `docs/specifications.md` — modèle métier, specs techniques, **décisions §8**
2. `docs/modele-de-donnees.md` — entités Doctrine, attributs, types,
   relations (vue de référence pour les migrations)
3. `docs/roadmap.md` — lot en cours, périmètre des lots à venir
4. `docs/workflow.md` — process issue → branche → PR → tag

Pour les sujets transverses : `docs/authentik.md` (OIDC), `docs/deploiement.md`
(prod), `docs/local-dev.md` (dev), `docs/qualite.md` (outils qualité).

## Conventions

- **Issue** : toute évolution démarre par une issue GitHub décrivant le besoin
  et les critères d'acceptation
- **Branche** : `feature/<n°>-<slug>` (ou `fix/`, `chore/`, `docs/`,
  `hotfix/`). Le `<n°>` est l'ID de l'issue. Exemple :
  `feature/42-archivage-projet`
- **Commits** : Conventional Commits en français (`feat:`, `fix:`, `docs:`,
  `chore:`, `refactor:`, `test:`). Un commit = une idée.
- **PR** : titre `<type>: <résumé> (#<n°>)`, body `Closes #<n°>` + résumé.
  Draft tant qu'en cours, **squash merge** à la fin.
- **Tag** : `vX.Y.Z` à la clôture d'un lot (ex. `v0.1.0` = Lot 0).

## Décisions structurantes (rappel — détail en specs §8)

- Symfony 7.x + Twig + Turbo/Stimulus + Tailwind, FrankenPHP en mode worker
- PHP 8.4, PostgreSQL 16, Redis 7
- Authentik OIDC (filtrage `OIDC_REQUIRED_GROUPS` en defense in depth)
- Référence `#YYYY-NNN` (compteurs séparés Project/Task)
- Tâches autonomes (sans projet parent) autorisées
- Visibilité par défaut : tout est visible aux agents authentifiés
- Effort en t-shirt sizing (XS/S/M/L/XL)
- Limites PJ : 25 Mo / 10 fichiers max
- Stockage des PJ derrière l'interface `AttachmentStorage` (anticipation GED)

## Commandes locales

À compléter au Lot 0. Cible : `make up`, `make test`, `make lint`, `make fix`.

## Avant de pousser

- Pas de `git push --force` sur `main` (jamais)
- Pas de commit de secrets (`.env` ignoré, `.env.example` à jour)
- Si tu modifies une décision structurante : mets à jour `docs/specifications.md`
  §8 (table des décisions) dans le **même commit**
- Si tu modifies les conventions de workflow : `docs/workflow.md` ET ce fichier

## Licence

AGPL-3.0 (cf. `LICENSE` à la racine). Le code de l'application doit donc
rester compatible AGPL — pas de dépendance propriétaire incompatible.

## Branche de la session courante

`claude/municipal-project-tool-b8H4z` (PR #1, draft, en cours de relecture).
