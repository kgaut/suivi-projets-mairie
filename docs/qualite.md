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

## 6. Pre-commit (optionnel)

Cible `make pre-commit` qui lance :

1. `php-cs-fixer fix` (autofix)
2. `twig-cs-fixer fix` (autofix)
3. `phpstan analyse`
4. `lint:twig`, `lint:yaml`

Sur le poste dev, tu peux la brancher dans un hook git :

```bash
echo 'make pre-commit' > .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

Pas imposé — certains préfèrent valider à la main avant push.

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
- **Sentry** auto-hébergé pour le suivi d'erreurs en prod
