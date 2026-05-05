# Environnement de développement local

Pas de Lando, pas de binaire `symfony`, juste **Docker Compose** : la stack dev est un miroir simplifié de la prod, ce qui évite les "ça marche chez moi mais pas en prod".

## 1. Prérequis

- Docker Engine 24+ et Docker Compose v2 (`docker compose version`)
- `make` et `git`
- 4 Go de RAM libres
- Un éditeur (VS Code / PhpStorm)

Pas besoin de PHP ou Composer installés en local : tout passe par les conteneurs.

## 2. Setup initial

```bash
git clone https://github.com/kgaut/suivi-projets-mairie.git
cd suivi-projets-mairie

# Copie le template d'env
cp .env.example .env.local

# Édite si besoin (notamment OIDC_*)
$EDITOR .env.local

# Démarre la stack
docker compose -f docker-compose.dev.yml up -d

# Première installation (composer install + migrations + fixtures)
make install
```

L'application est ensuite accessible sur :

| Service | URL |
|---|---|
| App | <http://localhost:8080> |
| Mailpit (interface mail dev) | <http://localhost:8025> |
| Postgres | `localhost:5432` (user/pass dans `.env.local`) |
| Redis | `localhost:6379` |

## 3. Stack dev (`docker-compose.dev.yml`)

Services lancés :

- **`app`** — image FrankenPHP buildée localement avec un Dockerfile dev (Xdebug, sources montées en volume, mode `dev`)
- **`postgres`** — PostgreSQL 16
- **`redis`** — Redis 7
- **`mailpit`** — capture les e-mails sortants pour les tester sans les envoyer

Voir `docker-compose.dev.yml.example` à la racine pour le squelette.

## 4. Commandes courantes (Makefile)

```bash
make help       # liste toutes les cibles

make install    # composer install + migrations + fixtures
make migrate    # applique les migrations en attente
make migration  # génère une nouvelle migration depuis les changements d'entités
make fixtures   # recharge les fixtures (DESTRUCTIF en local)
make reset      # drop + create + migrate + fixtures (tout reset)

make shell      # ouvre un shell dans le conteneur app
make logs       # tail -f des logs

make test       # lance phpunit
make test-unit  # uniquement les tests unitaires
make test-func  # uniquement les tests fonctionnels

make stan       # phpstan
make cs         # php-cs-fixer (apply)
make cs-check   # php-cs-fixer en mode dry-run
make rector     # rector (apply)
make twig-cs    # twig-cs-fixer
make deptrac    # deptrac
make audit      # composer audit
make qa         # tout ce qui précède en mode check (utilisé en CI locale)

make stop       # docker compose stop
make down       # docker compose down (garde les volumes)
make clean      # docker compose down -v (DESTRUCTIF : perte de la BDD)
```

Toutes ces cibles wrappent `docker compose exec app …`, donc tu n'as jamais à invoquer Docker manuellement.

## 5. Authentik en dev

Tu as deux options :

### 5.1 Utiliser ton instance Authentik existante (recommandé)

1. Crée un **second provider** dans ton Authentik avec `Redirect URI = http://localhost:8080/oidc/callback`.
2. Reporte les `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` dans `.env.local`.
3. Conserve les mêmes groupes que la prod, ou crée des groupes `dev-*` selon ta préférence.

### 5.2 Lancer une Authentik locale

Si tu veux tester sans dépendre du serveur principal :

```bash
# Dans un autre dossier
git clone https://github.com/goauthentik/authentik.git
cd authentik
docker compose up -d
# Suivre la doc Authentik pour le setup initial
```

Configure ensuite l'app pour pointer vers `http://localhost:9000`.

> Mode "stub" : si tu veux développer sans Authentik du tout, on peut prévoir un `OIDC_DRIVER=fake` qui simule un user `dev@local`. À implémenter au Lot 0 si tu juges utile.

## 6. Xdebug

Xdebug est désactivé par défaut (impact perfs). Pour l'activer :

```bash
# Dans .env.local
XDEBUG_MODE=debug
XDEBUG_CLIENT_HOST=host.docker.internal  # macOS/Windows
# Pour Linux : XDEBUG_CLIENT_HOST=172.17.0.1 (gateway docker)

docker compose -f docker-compose.dev.yml restart app
```

Configuration côté IDE : path mapping `/app` → racine du repo, port 9003.

## 7. Tests

```bash
make test                           # tous
make test ARGS="--filter=ProjectTest"   # ciblé
make test-coverage                  # avec couverture HTML dans var/coverage/
```

Les tests fonctionnels utilisent une **base dédiée** (`spm_test`) recréée avant chaque run, isolée de la BDD de dev.

## 8. Réinitialisation totale

Si tu veux repartir de zéro :

```bash
make clean        # détruit les conteneurs ET les volumes (BDD perdue)
make install      # repart à neuf
```

## 9. Outils IDE recommandés

- **VS Code** : extensions `PHP Intelephense`, `Symfony for VSCode`, `Twig Language 2`, `Stimulus`
- **PhpStorm** : déjà tout en natif, activer Symfony plugin et Stimulus plugin
- Configuration `.editorconfig` fournie à la racine

## 10. Astuces

- Les sources sont montées en volume → pas besoin de rebuild l'image après modification de code PHP/Twig.
- Pour un changement de dépendance (`composer.json`), `make install` suffit.
- Pour un changement dans le `Dockerfile` dev : `docker compose -f docker-compose.dev.yml build app` puis `up -d`.
- FrankenPHP en dev tourne en mode classique (pas worker) pour faciliter le debug — on n'active le mode worker qu'en prod.
