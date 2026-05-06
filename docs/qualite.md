# Qualité de code

L'objectif n'est pas d'empiler les outils mais d'avoir un filet de sécurité fiable et rapide qui tourne **en local** comme **en CI**, et qui bloque les régressions sans frustrer le développement.

## 1. Outils retenus

| Outil | Rôle | Bloquant en CI |
|---|---|---|
| **PHPStan** | Analyse statique | ✅ |
| **PHPUnit** | Tests unitaires + fonctionnels | ✅ |
| **PHP-CS-Fixer** | Formatage du code (preset Symfony + PSR-12) | ✅ |
| **Twig CS Fixer** | Formatage des templates | ✅ |
| **Rector** | Refactoring auto + règles upgrade | ⚠️ check-only en CI |
| **Deptrac** | Vérification des couches d'architecture | ✅ |
| **`composer audit`** | Vulnérabilités de dépendances | ✅ |
| **`doctrine:schema:validate`** | Cohérence schéma ↔ entités | ✅ |
| **`lint:twig` + `lint:yaml` + `lint:container`** | Lints natifs Symfony | ✅ |
| **GrumPHP** | Orchestrateur des hooks pre-commit (cf. §6) | n/a (local) |
| **Sentry** | Suivi d'erreurs en prod (cf. §10) | n/a (runtime) |

> Pourquoi PHP-CS-Fixer plutôt que PHP_CodeSniffer (PHPCS) : meilleur support du preset `@Symfony`, écosystème Symfony s'aligne dessus, autofix plus complet, et beaucoup mieux maintenu en 2026. Si tu as une préférence forte pour PHPCS, on peut le mettre à la place — préviens-moi.

## 2. Configuration

Chaque outil aura son fichier de config à la racine du repo (créés au Lot 0) :

- `phpstan.neon.dist` (level 9 / max + extensions Symfony et Doctrine)
- `phpunit.dist.xml`
- `.php-cs-fixer.dist.php` (preset `@Symfony` + `@PHP84Migration` + règles maison)
- `.twig-cs-fixer.dist.php`
- `rector.php` (sets : Symfony, Doctrine, PHP 8.4)
- `deptrac.yaml` (couches : Controller / Application / Domain / Infrastructure)
- `.editorconfig`

## 3. Règles d'architecture (Deptrac)

```
┌─────────────┐
│  Controller │  ──→ Application
└─────────────┘
       │
       ▼
┌─────────────┐
│ Application │  ──→ Domain
└─────────────┘
       │
       ▼
┌─────────────┐
│   Domain    │  ──→ (rien)
└─────────────┘
       ▲
       │
┌──────┴──────────┐
│ Infrastructure  │  ──→ Domain (interfaces)
└─────────────────┘
```

Règles strictes :

- `Controller` peut dépendre de `Application` mais **pas** de `Domain` directement (pour forcer le passage par les services applicatifs et faciliter la future API).
- `Application` peut dépendre de `Domain` (entités, VO, interfaces de repo).
- `Infrastructure` implémente les interfaces de `Domain` (pattern Repository) mais ne peut pas être référencé directement depuis `Application`/`Controller` (DI uniquement).
- `Domain` ne dépend de **rien** d'externe à lui-même (pas de Doctrine, pas de Symfony) — *sauf attributs Doctrine sur les entités, autorisés pour rester pragmatiques*.

## 4. Conventions

- **PSR-12** + preset Symfony.
- `declare(strict_types=1);` en tête de chaque fichier PHP.
- Attributs PHP 8 partout (jamais d'annotations).
- Noms en français pour les concepts métier (`Projet`, `Tache`)… ❓ **À décider** : on peut aussi rester en anglais (`Project`, `Task`) qui est l'usage Symfony et facilite les recherches Stack Overflow. Mon avis : **anglais** pour le code, **français** pour l'UI.
- Tests : suffixe `Test.php`, classes finales, `@covers` annotation pour bien cibler.
- Une migration par PR fonctionnelle ; pas de migration "fourre-tout".
- Commits conventionnels (`feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`, `ci:`).

## 5. Couverture de tests

| Couche | Cible |
|---|---|
| `Domain` | 90 % (logique pure, facile à tester) |
| `Application` | 70 % (services applicatifs) |
| `Infrastructure` | tests d'intégration sur les repositories critiques |
| `Controller` | tests fonctionnels sur les routes critiques (login, CRUD principal) |

Pas de seuil bloquant en CI au début (on évite la dictature de la couverture), mais le rapport est généré et publié sur chaque PR.

## 6. Pre-commit avec GrumPHP

On utilise [**GrumPHP**](https://github.com/phpro/grumphp) comme orchestrateur des hooks pre-commit. Il s'installe en require-dev, branche tout seul le hook git via Composer, et exécute les outils ci-dessus en parallèle.

### 6.1 Pourquoi GrumPHP plutôt qu'un Makefile + script shell

- Configuration unique dans `grumphp.yml` au lieu de scripts éparpillés
- Hook git auto-installé via `composer install` (rien à faire à la main quand un dev clone le repo)
- Sortie groupée et lisible des outils
- Possibilité de filtrer sur les fichiers modifiés uniquement (rapidité du commit)
- Désactivable temporairement pour un commit (`git commit --no-verify`) ou via une env var quand tu sais ce que tu fais

### 6.2 Configuration cible (à finaliser au Lot 0)

`grumphp.yml` à la racine :

```yaml
grumphp:
  process_timeout: 120
  stop_on_failure: false
  hooks_dir: ~
  tasks:
    composer:
      file: ./composer.json
      no_check_publish: true
    phpcsfixer:
      config: .php-cs-fixer.dist.php
      verbose: true
    twigcsfixer:
      paths: [templates]
    phpstan:
      configuration: phpstan.neon.dist
      memory_limit: -1
    twig: ~          # symfony/twig-bundle lint
    yamllint:
      whitelist_patterns:
        - /^config\/.*\.ya?ml$/
        - /^translations\/.*\.ya?ml$/
    securitychecker_enlightn: ~
```

### 6.3 Cadrage des hooks

- **`pre-commit`** (rapide, < 30 s) : `phpcsfixer`, `twigcsfixer`, `twig`, `yamllint`, `composer` — uniquement sur les fichiers modifiés
- **`pre-push`** (plus complet) : `phpstan`, `securitychecker` — pour garder le pre-commit fluide

Tu peux contourner ponctuellement avec `git commit --no-verify` si tu sais ce que tu fais (ex. WIP poussé sur ta branche perso).

## 7. CI : pipeline qualité

Workflow GitHub Actions `.github/workflows/ci.yml` (à créer au Lot 0) :

```
job lint
  ├─ composer install
  ├─ php-cs-fixer --dry-run
  ├─ twig-cs-fixer --dry-run
  ├─ phpstan
  ├─ lint:twig + lint:yaml + lint:container
  └─ deptrac

job test
  ├─ services postgres + redis
  ├─ composer install
  ├─ doctrine:schema:validate
  ├─ doctrine:migrations:migrate
  └─ phpunit (unit + functional)

job audit
  ├─ composer audit
  └─ rector --dry-run
```

3 jobs en parallèle, ~3-5 min total visé.

## 8. Outils écartés (et pourquoi)

| Outil | Raison du choix de ne pas l'utiliser |
|---|---|
| **Psalm** | Doublon avec PHPStan ; PHPStan a un meilleur support Symfony et un écosystème plus large en 2026 |
| **PHPCS** | Voir §1, écarté au profit de PHP-CS-Fixer |
| **Infection** (mutation testing) | Excellent mais coûteux en CI, à introduire plus tard si la couverture stabilise |
| **PHPMD** | Redondant avec PHPStan + Deptrac |
| **Behat** | Overkill pour un outil interne, PHPUnit + WebTestCase suffit |

À reconsidérer plus tard selon les besoins.

## 9. Outils complémentaires possibles (pour plus tard)

- **Trivy** sur l'image Docker construite par la CI release
- **pa11y-ci** sur quelques routes pour le RGAA
- **k6** ou **Artillery** pour des tests de charge avant la mise en prod

## 10. Monitoring d'erreurs : Sentry

On branche **Sentry** dès le Lot 0 pour le suivi d'erreurs en prod. Compte Sentry SaaS existant côté mairie (instance fournie par le PO).

### 10.1 Intégration Symfony

- Bundle officiel : [`sentry/sentry-symfony`](https://github.com/getsentry/sentry-symfony)
- Variable d'environnement : `SENTRY_DSN` (vide en dev, renseignée en staging/prod)
- Configuration dans `config/packages/sentry.yaml` :

```yaml
sentry:
  dsn: '%env(SENTRY_DSN)%'
  options:
    environment: '%kernel.environment%'
    release: '%env(APP_VERSION)%'         # mappe les erreurs au tag git
    send_default_pii: false                # RGPD : pas de cookies/IP par défaut
    traces_sample_rate: 0.1                # 10 % des transactions tracées (APM léger)
    profiles_sample_rate: 0.0              # pas de profiling continu en v1
    ignore_exceptions:
      - Symfony\Component\HttpKernel\Exception\NotFoundHttpException
      - Symfony\Component\Security\Core\Exception\AccessDeniedException
  register_error_listener: true
  register_error_handler: true
```

### 10.2 Bonnes pratiques

- **Ne pas envoyer de PII** : `send_default_pii: false`. Les données utilisateur (e-mail, IP, headers) restent locales.
- **Filtrer le bruit** : 404 et 403 ignorées par défaut (signalées dans les logs locaux, pas dans Sentry).
- **Release tracking** : la variable `APP_VERSION` (déjà présente dans `.env` de prod) sert de release Sentry, ce qui permet de voir "depuis quel tag les erreurs apparaissent".
- **Tags utiles** : ajout d'un tag `working_group` (groupe de travail courant) et `role` sur les exceptions agent, pour filtrer les erreurs par contexte métier.
- **Source maps PHP** : pas applicable, mais on uploade les **sourcemaps Stimulus/Tailwind** vers Sentry à chaque build (CI release) pour avoir des stack traces JS lisibles.
- **Workers Messenger** : Sentry capture aussi les erreurs des consommateurs Messenger (intégration native du bundle).

### 10.3 Dans les Lots à venir

- Lot 0 : intégration de base, capture d'erreurs serveur et JS
- Lot 4+ : envoi d'événements custom (ex. "PJ rejetée par antivirus") pour faire des dashboards
- Plus tard : alerting Sentry → mail / chat de l'admin
