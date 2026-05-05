# Déploiement en production

## 1. Architecture cible

```
                    ┌─────────────────┐
   Internet ───TLS──┤ Reverse proxy   │
                    │ (Traefik/Caddy) │
                    └────────┬────────┘
                             │ http
                    ┌────────▼─────────┐    ┌──────────┐
                    │ app (FrankenPHP) ├────┤ postgres │
                    │ Symfony worker   │    └──────────┘
                    └────────┬─────────┘    ┌──────────┐
                             ├──────────────┤ redis    │
                    ┌────────▼─────────┐    └──────────┘
                    │ worker (Messenger│
                    │ async)           │
                    └──────────────────┘
```

Le reverse proxy (Traefik recommandé si tu en as déjà un, sinon Caddy avec auto-TLS) termine TLS et délègue à FrankenPHP en HTTP. Authentik est sur une autre stack ou le même reverse proxy.

## 2. Prérequis serveur

- Linux récent (Debian 12 / Ubuntu 22.04+)
- Docker Engine 24+ et Docker Compose v2
- Reverse proxy avec TLS (Let's Encrypt) — Traefik ou Caddy
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
    networks: [internal, web]
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.spm.rule=Host(`projets.mairie.example.fr`)"
      - "traefik.http.routers.spm.tls.certresolver=letsencrypt"
      - "traefik.http.services.spm.loadbalancer.server.port=80"

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
    depends_on: [postgres, redis]
    networks: [internal]

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
    networks: [internal]

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
    networks: [internal]

volumes:
  pgdata:
  redisdata:
  uploads:
  logs:

networks:
  internal:
  web:
    external: true   # réseau partagé avec Traefik
```

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
OIDC_GROUP_ROLE_MAPPING="mairie-projets-lecteur:ROLE_LECTEUR,mairie-projets-agent:ROLE_AGENT,mairie-projets-chef:ROLE_CHEF_PROJET,mairie-projets-admin:ROLE_ADMIN"

TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
```

## 5. Premier déploiement

```bash
# Sur le serveur
mkdir -p /opt/suivi-projets-mairie && cd /opt/suivi-projets-mairie

# Récupère les fichiers de stack
curl -O https://raw.githubusercontent.com/kgaut/suivi-projets-mairie/main/docker-compose.prod.yml

# Crée et édite le fichier d'env
cp .env.example .env
$EDITOR .env

# Démarre la stack
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d

# Lance les migrations
docker compose -f docker-compose.prod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction
```

## 6. Mise à jour

```bash
# 1. Note la version actuelle
docker compose -f docker-compose.prod.yml ps app

# 2. Modifie APP_VERSION dans .env
$EDITOR .env

# 3. Pull et redémarre (rolling : worker puis app)
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d --no-deps worker
docker compose -f docker-compose.prod.yml up -d --no-deps app

# 4. Migrations
docker compose -f docker-compose.prod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction

# 5. Purge de cache Symfony (déjà faite par le entrypoint, mais pour info)
docker compose -f docker-compose.prod.yml exec app php bin/console cache:clear
```

> ⚠️ Toujours faire un `pg_dump` avant un déploiement comportant des migrations (cf. section 7).

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
