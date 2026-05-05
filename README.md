# Suivi Projets Mairie

Outil interne de gestion de projet pour une mairie : suivi des projets numériques, gestion des tâches, vision claire du travail en cours et à faire. Authentification SSO via Authentik, déploiement Docker.

> ⚠️ Projet en cours d'initialisation. Les specs sont en cours de rédaction (voir [`docs/specifications.md`](docs/specifications.md)). Aucun code applicatif pour le moment.

## Stack technique

- **Backend** : PHP 8.4 + Symfony 7.x
- **Serveur** : FrankenPHP (mode worker)
- **Base de données** : PostgreSQL 16
- **Cache / sessions / queues** : Redis 7
- **Front** : Twig + Symfony UX (Turbo + Stimulus)
- **Auth** : OIDC via Authentik
- **CI** : GitHub Actions (+ miroir GitLab CI)
- **Image** : publiée sur GHCR

## Documentation

| Sujet | Fichier |
|---|---|
| Spécifications fonctionnelles et techniques | [`docs/specifications.md`](docs/specifications.md) |
| Roadmap et lots de livraison | [`docs/roadmap.md`](docs/roadmap.md) |
| Configuration Authentik (côté IDP + côté app) | [`docs/authentik.md`](docs/authentik.md) |
| Déploiement en production (docker compose) | [`docs/deploiement.md`](docs/deploiement.md) |
| Mise en place de l'environnement local | [`docs/local-dev.md`](docs/local-dev.md) |
| Outils qualité et conventions de code | [`docs/qualite.md`](docs/qualite.md) |
| Workflow de contribution (issue → PR) | [`docs/workflow.md`](docs/workflow.md) |

## Démarrage rapide (à venir)

Une fois le squelette Symfony en place :

```bash
git clone https://github.com/kgaut/suivi-projets-mairie.git
cd suivi-projets-mairie
cp .env.example .env.local
docker compose -f docker-compose.dev.yml up -d
make install
```

L'application sera disponible sur <http://localhost:8080>, Mailpit sur <http://localhost:8025>.

Voir [`docs/local-dev.md`](docs/local-dev.md) pour le détail.

## Licence

À définir.
