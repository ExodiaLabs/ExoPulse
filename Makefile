.DEFAULT_GOAL := help
DC = docker compose
PHP = $(DC) exec -T php
NODE = docker run --rm --user "$$(id -u):$$(id -g)" -e HOME=/tmp -v "$(CURDIR):/app" -w /app node:24-alpine@sha256:44fce696f38ba8c987c4db89954051000bbd05ffd8d8659ba15ed4a8602e937f

.PHONY: help install up down build test lint phpstan cs-check cs-fix quality migrate env-checker frontend worker
help: ## Afficher les commandes
	@awk 'BEGIN {FS = ":.*## "} /^[a-z-]+:.*## / {printf "%-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
install: build up ## Installer dépendances et initialiser la base locale
	$(PHP) composer install --no-interaction
	$(MAKE) frontend migrate
up: ## Démarrer les services (volumes conservés)
	$(DC) up -d --wait php web database
down: ## Arrêter les services sans supprimer les données
	$(DC) --profile worker down
build: ## Construire l'image PHP de développement
	$(DC) build php
frontend: ## Installer et compiler le thème
	$(NODE) npm ci
	$(NODE) npm run check
	$(NODE) npm run build
worker: ## Démarrer le worker après migrations
	$(DC) --profile worker up -d worker
test: ## Exécuter PHPUnit
	$(PHP) vendor/bin/phpunit
lint: ## Vérifier manifestes et configuration Symfony
	$(PHP) composer validate --strict
	$(PHP) php bin/console lint:yaml config
	$(PHP) php bin/console lint:twig templates
	$(PHP) php bin/console lint:container
phpstan: ## Exécuter PHPStan
	$(PHP) vendor/bin/phpstan analyse --no-progress
cs-check: ## Vérifier le style Symfony
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff
cs-fix: ## Corriger le style Symfony
	$(PHP) vendor/bin/php-cs-fixer fix
quality: lint phpstan cs-check test frontend ## Exécuter les contrôles locaux
migrate: ## Jouer les migrations et préparer Messenger
	$(PHP) php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(PHP) php bin/console messenger:setup-transports --no-interaction
env-checker: ## Comparer les noms des variables sans afficher leurs valeurs
	$(PHP) php scripts/env-checker.php --ansi
