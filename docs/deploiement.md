# Déploiement en production

## 1. Architecture cible

```
                    ┌─────────────────┐
   Internet ───TLS──┤ Caddy           │      (reverse proxy)
                    │ (Let's Encrypt) │
                    └────────┬────────┘
                             │ http (réseau caddy_net)
                    ┌────────▼─────────┐    ┌──────────┐
                    │ app (FrankenPHP) ├────┤ postgres │  ⎫
                    │ Symfony worker   │    └──────────┘  ⎬ réseau
                    └────────┬─────────┘    ┌──────────┐  ⎪ internal_net
                             ├──────────────┤ redis    │  ⎭
                    ┌────────▼─────────┐    └──────────┘
                    │ worker (Messenger│
                    │ async)           │
                    └──────────────────┘
```

Choix tranché : **Caddy uniquement** comme reverse proxy (pas de Traefik en parallèle). FrankenPHP fournit Caddy embarqué, et on en utilise une instance dédiée en frontal pour terminer TLS, gérer les certificats Let's Encrypt et router vers les différentes apps (Authentik, Suivi Projets, etc.) — Authentik vit sur la même machine et partage ce Caddy.

**Isolation réseau** : deux réseaux Docker séparés (cf. §3) :

- `caddy_net` (externe) — uniquement caddy ↔ app. Les services internes (db, redis, worker) n'y sont pas exposés.
- `internal_net` (interne au projet) — app ↔ postgres ↔ redis ↔ worker. Pas accessible depuis le reverse proxy.

Cette séparation évite qu'une compromission du conteneur frontal ouvre un accès direct à la base ou au cache.

## 2. Prérequis serveur

- Linux récent (Debian 12 / Ubuntu 22.04+)
- Docker Engine 24+ et Docker Compose v2
- **Caddy** en frontal (instance partagée avec les autres apps de la mairie comme Authentik), TLS via Let's Encrypt
- Réseau Docker externe `caddy_net` créé une fois pour toutes (`docker network create caddy_net`)
- Accès réseau sortant pour `ghcr.io` (pull des images) et l'instance Authentik
- DNS pointant vers le serveur pour `projets.mairie.example.fr`

## 3. Stack docker compose

Fichier `docker-compose.prod.yml` (exemple, à finaliser au Lot 0) :

```yaml
services:
  app:
    image: ghcr.io/kgaut/suivi-projets-mairie:${APP_VERSION:-latest}
    restart: unless-stopped
    env_file: .env
    environment:
      APP_ENV: prod
      APP_DEBUG: 0
    volumes:
      - uploads:/app/var/uploads
      - logs:/app/var/log
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
      migrate:
        condition: service_completed_successfully
    networks: [internal_net, caddy_net]
    # Routage Caddy : la conf est dans le Caddyfile global (cf. §3.1)
    # Pas de labels ici — l'app n'a pas conscience du proxy.

  worker:
    image: ghcr.io/kgaut/suivi-projets-mairie:${APP_VERSION:-latest}
    restart: unless-stopped
    env_file: .env
    environment:
      APP_ENV: prod
      APP_DEBUG: 0
    command: ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600", "--memory-limit=256M"]
    volumes:
      - uploads:/app/var/uploads
      - logs:/app/var/log
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
      migrate:
        condition: service_completed_successfully
    networks: [internal_net]

  # Service one-shot : exécute les migrations Doctrine puis sort.
  # Lancé automatiquement par `up -d` (les autres services attendent qu'il finisse via depends_on).
  # Permet de bloquer le démarrage de app/worker si une migration échoue, sans embarquer
  # la logique dans un entrypoint applicatif (cf. §6.1 pour la justification du choix).
  migrate:
    image: ghcr.io/kgaut/suivi-projets-mairie:${APP_VERSION:-latest}
    env_file: .env
    environment:
      APP_ENV: prod
      APP_DEBUG: 0
    command: ["php", "bin/console", "doctrine:migrations:migrate", "--no-interaction", "--allow-no-migration"]
    depends_on:
      postgres:
        condition: service_healthy
    networks: [internal_net]
    restart: "no"

  postgres:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${POSTGRES_DB}
      POSTGRES_USER: ${POSTGRES_USER}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
    volumes:
      - pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $$POSTGRES_USER"]
      interval: 5s
      retries: 10
    networks: [internal_net]

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--save", "60", "1", "--maxmemory", "256mb", "--maxmemory-policy", "allkeys-lru"]
    volumes:
      - redisdata:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      retries: 10
    networks: [internal_net]

volumes:
  pgdata:
  redisdata:
  uploads:
  logs:

networks:
  # Réseau interne au projet : app, worker, postgres, redis, migrate.
  # Pas exposé à l'extérieur, pas accessible depuis le reverse proxy.
  internal_net:
    driver: bridge
  # Réseau partagé avec le Caddy frontal et les autres apps de la mairie.
  # Seul le service `app` y est attaché ; postgres/redis ne sont JAMAIS dessus.
  caddy_net:
    external: true
```

### 3.1 Configuration Caddy (frontal)

Le Caddy frontal (instance partagée avec les autres apps) ajoute un bloc dans son `Caddyfile` :

```caddy
projets.mairie.example.fr {
    encode zstd gzip
    reverse_proxy app:80
    log {
        output file /var/log/caddy/spm.log
    }
}
```

`app` est résolu via le réseau Docker `caddy_net` (le service `app` du compose y est attaché).

## 4. Variables d'environnement (`.env`)

À créer sur le serveur, **ne jamais committer** :

```dotenv
APP_VERSION=v0.1.0
APP_SECRET=<openssl rand -hex 32>

DATABASE_URL=postgresql://spm:CHANGEMOI@postgres:5432/spm?serverVersion=16&charset=utf8
POSTGRES_DB=spm
POSTGRES_USER=spm
POSTGRES_PASSWORD=CHANGEMOI

REDIS_URL=redis://redis:6379

MAILER_DSN=smtp://user:pass@smtp.mairie.example.fr:587

OIDC_ISSUER_URL=https://authentik.mairie.example.fr/application/o/suivi-projets-mairie/
OIDC_CLIENT_ID=...
OIDC_CLIENT_SECRET=...
OIDC_REDIRECT_URI=https://projets.mairie.example.fr/oidc/callback
OIDC_SCOPES="openid email profile groups"
OIDC_ADMIN_GROUP=admin_spm
OIDC_REQUIRED_GROUPS=spm_users

TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
```

## 5. Premier déploiement

```bash
# Sur le serveur
mkdir -p /opt/suivi-projets-mairie && cd /opt/suivi-projets-mairie

# Crée le réseau Docker partagé avec Caddy (une seule fois)
docker network create caddy_net 2>/dev/null || true

# Récupère les fichiers de stack
curl -O https://raw.githubusercontent.com/kgaut/suivi-projets-mairie/main/docker-compose.prod.yml

# Crée et édite le fichier d'env
cp .env.example .env
$EDITOR .env

# Démarre la stack — le service `migrate` s'exécute automatiquement avant app/worker
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

## 6. Mise à jour

```bash
# 1. Backup base avant migration (cf. §7)
/etc/cron.daily/spm-backup   # ou exécution manuelle

# 2. Note la version actuelle
docker compose -f docker-compose.prod.yml ps app

# 3. Modifie APP_VERSION dans .env
$EDITOR .env

# 4. Pull les nouvelles images
docker compose -f docker-compose.prod.yml pull

# 5. Migrations (one-shot, bloque la suite si une migration échoue)
docker compose -f docker-compose.prod.yml run --rm migrate

# 6. Redémarre app et worker (rolling : worker en premier puis app)
docker compose -f docker-compose.prod.yml up -d --no-deps worker
docker compose -f docker-compose.prod.yml up -d --no-deps app

# 7. (optionnel) Purge de cache Symfony — déjà faite par l'entrypoint applicatif
docker compose -f docker-compose.prod.yml exec app php bin/console cache:clear
```

> ⚠️ Toujours faire un `pg_dump` avant un déploiement comportant des migrations (cf. section 7).

### 6.1 Pourquoi un service `migrate` one-shot et pas un entrypoint applicatif ?

Trois options classiques :

| Option | Description | Choix |
|---|---|---|
| **A. Entrypoint applicatif** | L'image app exécute `doctrine:migrations:migrate` au démarrage avant de servir le trafic | ❌ |
| **B. Commande manuelle après déploiement** | L'opérateur lance la commande à la main une fois la stack démarrée | ❌ |
| **C. Service `migrate` one-shot dans le compose** | Container dédié qui run-once et bloque les autres services via `depends_on` + `service_completed_successfully` | ✅ **Retenu** |

L'option C combine les avantages des deux autres :

- **Automatique** comme A : un `docker compose up -d` suffit, pas d'étape manuelle à oublier.
- **Explicite** comme B : la migration est un service nommé, ses logs sont accessibles via `docker compose logs migrate`, son exit code est observable.
- **Bloquant en cas d'échec** : si la migration échoue, app et worker ne démarrent pas (pas de trafic vers une base incohérente).
- **Pas de race condition** : si demain on passe à plusieurs replicas de l'app, seul le service `migrate` exécute la commande, une seule fois.
- **Pas de dépendance dans l'image app** : l'entrypoint reste minimal (juste FrankenPHP), la migration est orchestrée au niveau compose, ce qui correspond mieux à la séparation des responsabilités.

Pour un déploiement séparé (CI/CD distant qui pousse une image et veut migrer manuellement), `docker compose run --rm migrate` reste la commande canonique.

### Rollback

```bash
# Repasse APP_VERSION sur la version précédente dans .env
docker compose -f docker-compose.prod.yml up -d
# Si la migration n'est pas rétrocompatible, restaurer le dump (cf. 7.2)
```

## 7. Sauvegardes

### 7.1 Base Postgres

Cron quotidien (à 3h du matin), conservation 30 jours :

```bash
# /etc/cron.daily/spm-backup
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR=/var/backups/spm
mkdir -p "$BACKUP_DIR"
docker compose -f /opt/suivi-projets-mairie/docker-compose.prod.yml exec -T postgres \
  pg_dump -U spm -d spm --format=custom \
  > "$BACKUP_DIR/spm-$(date +%Y%m%d).dump"
find "$BACKUP_DIR" -name 'spm-*.dump' -mtime +30 -delete
```

> Recommandation : copier les dumps vers un stockage offsite (rsync vers un autre serveur, ou bucket S3/Garage).

### 7.2 Restauration

```bash
# Stack à l'arrêt côté app
docker compose -f docker-compose.prod.yml stop app worker
docker compose -f docker-compose.prod.yml exec -T postgres \
  pg_restore -U spm -d spm --clean --if-exists < /var/backups/spm/spm-20260101.dump
docker compose -f docker-compose.prod.yml start app worker
```

### 7.3 Pièces jointes

Le volume `uploads` doit être inclus dans la sauvegarde (rsync ou snapshot du volume).

## 8. CI/CD : publication des images

### 8.1 GitHub Actions

Deux workflows à créer au Lot 0 :

- `.github/workflows/ci.yml` : sur chaque push/PR — installe deps, lance phpstan, php-cs-fixer (check), tests, doctrine:schema:validate, composer audit.
- `.github/workflows/release.yml` : sur tag `v*` — build multi-arch (linux/amd64, linux/arm64), push sur `ghcr.io/kgaut/suivi-projets-mairie:vX.Y.Z` + `:latest`.

L'image utilise le tag `vX.Y.Z` en prod, jamais `:latest`. `:latest` n'existe que pour faciliter les tests.

### 8.2 GitLab CI miroir

Un `.gitlab-ci.yml` équivalent permet à une autre mairie de forker sur GitLab et conserver la CI. Stages identiques : `lint`, `test`, `build`, `release`. Image construite avec Kaniko ou BuildKit, poussée sur le registre GitLab du projet ou GHCR (token configurable).

## 9. Observabilité

### v1 (minimum)

- Logs applicatifs sur stdout (récupérés par Docker), envoi optionnel vers un Loki/Graylog.
- Healthcheck Symfony : route `/healthz` (à créer au Lot 0) qui vérifie BDD + Redis et retourne 200/503.

### v1.x (à venir)

- Métriques Prometheus exposées sur `/metrics` (auth basique)
- Dashboard Grafana

## 10. Sécurité

- `APP_SECRET` régénéré pour chaque environnement.
- `TRUSTED_PROXIES` configuré pour éviter les usurpations de `X-Forwarded-*`.
- Pas de port BDD/Redis exposés vers l'extérieur (réseau `internal` uniquement).
- Mises à jour de l'image régulières (au moins mensuelles, plus tôt si CVE).
- `composer audit` lancé en CI bloque la PR si une dépendance a une CVE.
- Scan d'image : on peut ajouter Trivy dans `release.yml` plus tard.
