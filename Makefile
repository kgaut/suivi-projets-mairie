# Makefile pour Suivi Projets Mairie
# Wrap les commandes Docker / Symfony / qualité pour ne jamais avoir à les
# invoquer manuellement.
#
# Usage : make help

# -- Variables ---------------------------------------------------------------

DOCKER_COMPOSE = docker compose -f docker-compose.dev.yml
EXEC_APP       = $(DOCKER_COMPOSE) exec app
COMPOSER       = $(EXEC_APP) composer
CONSOLE        = $(EXEC_APP) php bin/console
PHPUNIT        = $(EXEC_APP) vendor/bin/phpunit

# Cible par défaut
.DEFAULT_GOAL := help

# Toutes les cibles sont .PHONY (pas de fichier produit)
.PHONY: help \
        up down stop clean logs build \
        install migrate migration fixtures reset \
        shell tailwind \
        test test-unit test-func test-coverage \
        stan cs cs-check rector twig-cs deptrac audit qa

# -- Aide --------------------------------------------------------------------

help: ## Affiche cette aide (cibles disponibles + description)
	@awk 'BEGIN {FS = ":.*?## "; printf "\nCibles disponibles :\n\n"} \
	     /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2} \
	     /^## ---/ {printf "\n\033[1m%s\033[0m\n", substr($$0, 5)}' \
	     $(MAKEFILE_LIST)

## --- Démarrage ------------------------------------------------------------

up: ## Démarre la stack dev (FrankenPHP, Postgres, Redis, Mailpit)
	$(DOCKER_COMPOSE) up -d
	@echo ""
	@echo "  → App disponible sur https://spm.localhost (HTTPS, CA Caddy à approuver — cf. docs/local-dev.md §3.1)"
	@echo "  → Mailpit  disponible sur http://localhost:8025"
	@echo ""

down: ## Arrête la stack et supprime les conteneurs (garde les volumes)
	$(DOCKER_COMPOSE) down

stop: ## Arrête la stack sans supprimer les conteneurs
	$(DOCKER_COMPOSE) stop

clean: ## ⚠ DESTRUCTIF : arrête la stack ET supprime les volumes (BDD + CA Caddy perdues)
	@printf "Cette action va supprimer les volumes Docker (BDD, vendor, CA Caddy). Continuer ? [y/N] "; \
	read REPLY; \
	if [ "$$REPLY" = "y" ] || [ "$$REPLY" = "Y" ]; then \
		$(DOCKER_COMPOSE) down -v; \
		echo "✓ Stack et volumes supprimés"; \
	else \
		echo "Annulé"; \
	fi

logs: ## Tail -f des logs de tous les services
	$(DOCKER_COMPOSE) logs -f

build: ## Rebuild les images Docker (utile après modif du Dockerfile)
	$(DOCKER_COMPOSE) build

## --- Installation et base de données --------------------------------------

install: ## Composer install + migrations + fixtures (premier setup)
	$(COMPOSER) install --no-interaction
	@$(MAKE) --no-print-directory migrate
	@$(MAKE) --no-print-directory fixtures

migrate: ## Applique les migrations Doctrine en attente
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

migration: ## Génère une nouvelle migration depuis les diffs d'entités
	$(CONSOLE) doctrine:migrations:diff --no-interaction

fixtures: ## ⚠ DESTRUCTIF en local : recharge les fixtures Doctrine
	$(CONSOLE) doctrine:fixtures:load --no-interaction --quiet || \
		echo "  (pas encore de fixtures — ignoré)"

reset: ## ⚠ DESTRUCTIF : drop + create + migrate + fixtures
	$(CONSOLE) doctrine:database:drop --force --if-exists
	$(CONSOLE) doctrine:database:create
	@$(MAKE) --no-print-directory migrate
	@$(MAKE) --no-print-directory fixtures

## --- Développement --------------------------------------------------------

shell: ## Ouvre un shell bash dans le conteneur app
	$(EXEC_APP) sh

tailwind: ## Lance Tailwind en mode --watch (rebuild auto sur modif)
	$(EXEC_APP) php bin/console tailwind:build --watch

## --- Tests ----------------------------------------------------------------

test: ## Lance phpunit (accepte ARGS=... pour filtrer, ex: make test ARGS="--filter=ProjectTest")
	$(PHPUNIT) $(ARGS)

test-unit: ## Lance uniquement les tests unitaires (testsuite Unit)
	$(PHPUNIT) --testsuite Unit

test-func: ## Lance uniquement les tests fonctionnels (testsuite Functional)
	$(PHPUNIT) --testsuite Functional

test-coverage: ## Lance phpunit avec couverture HTML (var/coverage/)
	$(EXEC_APP) sh -c 'XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html=var/coverage'
	@echo "  → Rapport disponible dans var/coverage/index.html"

## --- Qualité --------------------------------------------------------------

stan: ## PHPStan (analyse statique, level défini dans phpstan.neon.dist)
	$(EXEC_APP) vendor/bin/phpstan analyse --memory-limit=-1

cs: ## PHP-CS-Fixer en mode apply (autofix du formatage)
	$(EXEC_APP) vendor/bin/php-cs-fixer fix

cs-check: ## PHP-CS-Fixer en mode dry-run (échoue si formatage à corriger)
	$(EXEC_APP) vendor/bin/php-cs-fixer fix --dry-run --diff

rector: ## Rector en mode apply (refactos automatiques)
	$(EXEC_APP) vendor/bin/rector process

twig-cs: ## Twig CS Fixer en mode apply
	$(EXEC_APP) vendor/bin/twig-cs-fixer fix templates

deptrac: ## Vérifie les couches d'architecture (Controller / Application / Domain / Infrastructure)
	$(EXEC_APP) vendor/bin/deptrac analyse

audit: ## composer audit + lint des fichiers de config Symfony
	$(COMPOSER) audit
	$(CONSOLE) lint:yaml config translations
	$(CONSOLE) lint:twig templates
	$(CONSOLE) lint:container

qa: cs-check stan twig-cs deptrac audit ## Toutes les vérifs qualité en mode check (utilisé par CI locale)
	@echo "✓ QA check OK"
