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
| App | <https://spm.localhost> (HTTPS, voir §3 pour le TLS local) |
| Mailpit (interface mail dev) | <http://localhost:8025> |
| Postgres | `localhost:5432` (user/pass dans `.env.local`) |
| Redis | `localhost:6379` |

> Pourquoi `spm.localhost` ? Le TLD `.localhost` est résolu automatiquement vers `127.0.0.1` (RFC 6761) — pas besoin d'éditer `/etc/hosts`. Si tu préfères un domaine type `projets.mairie.test`, surcharge `DEV_SERVER_NAME` dans `.env.local` et ajoute la ligne correspondante à ton `/etc/hosts`.

## 3. TLS local (au plus près de la prod)

L'app dev est servie en **HTTPS** par FrankenPHP/Caddy, comme en prod. Caddy génère un certificat signé par sa propre CA locale, persistée dans le volume `caddy_data` (donc pas régénérée à chaque `docker compose up`).

### 3.1 Première configuration : approuver la CA Caddy

Au tout premier démarrage, ton navigateur va afficher un warning de sécurité parce que la CA Caddy n'est pas connue. Pour l'approuver une fois pour toutes (recommandé) :

```bash
# Récupère le certificat racine Caddy depuis le conteneur
docker compose -f docker-compose.dev.yml exec app cat /data/caddy/pki/authorities/local/root.crt > /tmp/caddy-root.crt

# Linux (Debian/Ubuntu)
sudo cp /tmp/caddy-root.crt /usr/local/share/ca-certificates/caddy-local.crt
sudo update-ca-certificates

# macOS
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain /tmp/caddy-root.crt

# Windows (PowerShell admin)
Import-Certificate -FilePath C:\path\to\caddy-root.crt -CertStoreLocation Cert:\LocalMachine\Root
```

Pour Firefox (qui a son propre trust store) : `Préférences → Confidentialité et sécurité → Certificats → Voir les certificats → Autorités → Importer`.

> **Alternative** : si tu préfères [mkcert](https://github.com/FiloSottile/mkcert), tu peux générer ton propre certificat et le monter dans le conteneur (volume `./docker/dev/certs:/etc/caddy/certs`) puis ajouter une directive `tls /etc/caddy/certs/spm.crt /etc/caddy/certs/spm.key` au `Caddyfile` dev. À configurer au Lot 0 si tu pars sur cette approche.

### 3.2 Domaine personnalisé

Pour utiliser un autre domaine que `spm.localhost` (ex. `projets.mairie.test`) :

```dotenv
# .env.local
DEV_SERVER_NAME=projets.mairie.test
```

```bash
# /etc/hosts
127.0.0.1 projets.mairie.test
```

Puis `docker compose -f docker-compose.dev.yml up -d` régénère le certificat pour ce domaine.

## 4. Stack dev (`docker-compose.dev.yml`)

Services lancés :

- **`app`** — image FrankenPHP (cible `dev`) buildée localement via `docker/Dockerfile`, sources montées en volume, Xdebug installé désactivé par défaut
- **`postgres`** — PostgreSQL 16
- **`redis`** — Redis 7
- **`mailpit`** — capture les e-mails sortants pour les tester sans les envoyer

Le compose se trouve à la racine : `docker-compose.dev.yml`.

## 5. Commandes courantes (Makefile)

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

## 6. Authentik en dev

Tu as deux options :

### 6.1 Utiliser ton instance Authentik existante (recommandé)

1. Crée un **second provider** dans ton Authentik avec `Redirect URI = https://spm.localhost/oidc/callback` (ou ton `DEV_SERVER_NAME` personnalisé).
2. Reporte les `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` dans `.env.local`.
3. Conserve les mêmes groupes que la prod, ou crée des groupes `dev-*` selon ta préférence.

> ⚠️ Authentik exige des Redirect URIs en `https://` (sauf pour `http://localhost`). Avec `spm.localhost` en HTTPS, c'est conforme et tu profites du même flow OIDC qu'en prod (cookies `Secure`, etc.).

### 6.2 Lancer une Authentik locale

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

## 7. Xdebug

Xdebug est désactivé par défaut (impact perfs). Pour l'activer :

```bash
# Dans .env.local
XDEBUG_MODE=debug
XDEBUG_CLIENT_HOST=host.docker.internal  # macOS/Windows
# Pour Linux : XDEBUG_CLIENT_HOST=172.17.0.1 (gateway docker)

docker compose -f docker-compose.dev.yml restart app
```

Configuration côté IDE : path mapping `/app` → racine du repo, port 9003.

## 8. Tests

```bash
make test                           # tous
make test ARGS="--filter=ProjectTest"   # ciblé
make test-coverage                  # avec couverture HTML dans var/coverage/
```

Les tests fonctionnels utilisent une **base dédiée** (`spm_test`) recréée avant chaque run, isolée de la BDD de dev.

## 9. Réinitialisation totale

Si tu veux repartir de zéro :

```bash
make clean        # détruit les conteneurs ET les volumes (BDD + CA Caddy perdues)
make install      # repart à neuf
```

> ⚠️ `make clean` détruit aussi le volume `caddy_data` qui contient la CA locale. Tu devras donc ré-approuver la nouvelle CA dans ton trust store (cf. §3.1).

## 10. Outils IDE recommandés

- **VS Code** : extensions `PHP Intelephense`, `Symfony for VSCode`, `Twig Language 2`, `Stimulus`
- **PhpStorm** : déjà tout en natif, activer Symfony plugin et Stimulus plugin
- Configuration `.editorconfig` fournie à la racine

## 11. Astuces

- Les sources sont montées en volume → pas besoin de rebuild l'image après modification de code PHP/Twig.
- Pour un changement de dépendance (`composer.json`), `make install` suffit.
- Pour un changement dans le `Dockerfile` dev : `docker compose -f docker-compose.dev.yml build app` puis `up -d`.
- FrankenPHP en dev tourne en mode classique (pas worker) pour faciliter le debug — on n'active le mode worker qu'en prod.
