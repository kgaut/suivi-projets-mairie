# Workflow de contribution

## 1. Vue d'ensemble

```
Idée ──→ Issue GitHub ──→ Branche ──→ Commits ──→ PR ──→ Review/CI ──→ Squash merge
                                                                            │
                                                                            ▼
                                                                  Lot complet → tag vX.Y.Z
```

Toute modification de code passe par ce cycle. Pas de push direct sur `main` (branche protégée).

## 2. Branches

| Branche | Rôle |
|---|---|
| `main` | Branche de référence, toujours déployable, protégée |
| `feature/<n°>-<slug>` | Nouvelle fonctionnalité (`feature/42-projet-archivage`) |
| `fix/<n°>-<slug>` | Correctif (`fix/57-mauvais-mapping-roles`) |
| `chore/<n°>-<slug>` | Tâche technique sans impact fonctionnel (`chore/12-bump-symfony`) |
| `docs/<n°>-<slug>` | Doc seule (`docs/3-precision-rgpd`) |
| `hotfix/<n°>-<slug>` | Correctif urgent depuis un tag |

Le numéro est celui de l'issue GitHub correspondante.

## 3. Cycle de vie d'une fonctionnalité

### 3.1 Issue

Avant tout code, on rédige une **issue** comprenant :

- **Contexte** : pourquoi on fait ça
- **Description fonctionnelle** : ce que l'utilisateur doit pouvoir faire
- **Critères d'acceptation** (cases à cocher, vérifiables)
- **Implications techniques** : entités touchées, migrations, impact sécurité
- **Hors scope** : ce qu'on ne fait *pas* dans cette issue
- **Lot rattaché** : `Lot 1`, `Lot 2`…

Template d'issue à fournir dans `.github/ISSUE_TEMPLATE/feature.yml` (Lot 0).

### 3.2 Branche

```bash
git checkout main
git pull --ff-only
git checkout -b feature/42-projet-archivage
```

### 3.3 Commits

Format **Conventional Commits** :

```
feat: ajoute l'archivage d'un projet
fix: corrige le mapping des rôles quand groups est vide
chore: passe Symfony 7.2 à 7.3
docs: précise la procédure de restauration
```

Un commit = une idée. On ne s'interdit pas plusieurs commits par PR si ça raconte mieux l'histoire (la PR sera squash-merged de toute façon, mais l'historique de la branche aide la review).

### 3.4 Push et PR

```bash
git push -u origin feature/42-projet-archivage
```

Une fois la branche poussée, ouverture d'une **PR** avec :

- **Titre** : `feat: ajoute l'archivage d'un projet (#42)`
- **Body** : `Closes #42` + résumé des changements + captures écran si UI
- **Reviewer** assigné automatiquement (toi)
- **Labels** : `lot/1`, `type/feat` (à voir si on automatise)

Template de PR dans `.github/pull_request_template.md` (Lot 0).

### 3.5 Review

- La CI doit être verte (lint + tests + phpstan + audit + deptrac).
- Au moins une review approuvée (toi).
- Discussions résolues.
- Pas de conflit avec `main`.

### 3.6 Merge

- **Squash and merge** systématique → un commit propre par fonctionnalité sur `main`.
- Le titre du commit de merge reprend le titre de la PR.
- La branche est supprimée après merge.

## 4. Lots et tags

### 4.1 Création d'un tag

Quand toutes les issues d'un lot sont mergées sur `main` :

```bash
git checkout main
git pull --ff-only
git tag -a v0.1.0 -m "Lot 0 — Fondations"
git push origin v0.1.0
```

Le push du tag déclenche le workflow `release.yml` qui :

1. Build l'image multi-arch
2. La pousse sur GHCR avec les tags `v0.1.0` et `latest`
3. Crée une **GitHub Release** avec le changelog du lot

### 4.2 Changelog

Maintenu dans `CHANGELOG.md` (à créer au moment du premier tag), au format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/). Mis à jour à chaque PR (section `## [Unreleased]`).

### 4.3 Versioning

[SemVer](https://semver.org/lang/fr/) :

- **MAJOR** (`v1.0.0`, `v2.0.0`) : changements incompatibles (rare, ex. refonte API publique)
- **MINOR** (`v0.1.0`, `v0.2.0`) : nouveau lot, ajouts compatibles
- **PATCH** (`v0.1.1`) : correctifs sur un lot existant

Tant qu'on est en `0.x`, l'API n'est pas stable et on s'autorise des breaking changes mineurs.

## 5. Hotfix

Pour un correctif urgent en prod :

```bash
git checkout v0.1.0
git checkout -b hotfix/99-bug-critique
# … fix …
git push -u origin hotfix/99-bug-critique
```

PR vers `main`. Une fois mergée :

```bash
git checkout main && git pull
git tag -a v0.1.1 -m "Hotfix bug critique #99"
git push origin v0.1.1
```

## 6. Règles de protection de `main` (à configurer côté GitHub)

- Require pull request before merging (1 reviewer)
- Require status checks to pass : `lint`, `test`, `audit`
- Require branches to be up to date before merging
- Require linear history (force squash/rebase)
- Do not allow bypassing the above settings

## 7. Conventions de nommage

| Élément | Format | Exemple |
|---|---|---|
| Branche | `<type>/<n°>-<slug-court>` | `feature/42-archivage-projet` |
| Issue | titre court à l'infinitif | "Permettre l'archivage d'un projet" |
| PR | `<type>: <titre> (#<n°>)` | `feat: ajoute l'archivage d'un projet (#42)` |
| Commit | conventional commits | `feat: ajoute l'archivage d'un projet` |
| Tag | `vX.Y.Z` | `v0.1.0` |
| Release notes | "Lot N — Nom du lot" | "Lot 0 — Fondations" |

## 8. Rôles

- **Toi (kgaut)** : product owner, reviewer, releaser, tu prioritises et valides
- **Moi (Claude)** : rédaction des issues à partir de tes specs, implémentation sur branche, ouverture de PR, ajustements selon ta review
