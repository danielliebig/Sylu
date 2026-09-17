# ===========================================================================
#  Sulu & Sylius Kickstarter  -  Makefile
#  Verified against Sylius 2.2.9 / Symfony 7.4.18 / PHP 8.5.10
#
#  First install, stage by stage (each individually repeatable):
#      make docker-build
#      make install-apps
#      make docker-start
#      make deps
#      make verify        <- STOP HERE and check the output
#      make fixtures
#      make test-checkout
#
#  Or all at once:  make setup
# ===========================================================================

SHELL := /bin/bash

# -f explicitly: Sylius Standard ships its own compose.yml, which would
#                otherwise take precedence.
# --env-file:    .env belongs to Symfony, not docker compose.
DC   := docker compose -f docker-compose.yaml --env-file .env.docker
SYL  := $(DC) exec -T sylius
SULU := $(DC) exec -T sulu
CSYL  := $(SYL)  php bin/console
CSULU := $(SULU) php bin/console

HTTP_PORT ?= 80
ifeq ($(HTTP_PORT),80)
  BASE := http://localhost
else
  BASE := http://localhost:$(HTTP_PORT)
endif

# Sylius admin runs on its own port, separate from the main storefront
# port above - see FIXES.md No. 28 (both Sulu and Sylius build their
# admin assets under the identical path /build/admin/, so Sylius admin
# needed its own, unprefixed origin to avoid the collision).
SYLIUS_ADMIN_PORT ?= 8082
SYLIUS_ADMIN_BASE := http://localhost:$(SYLIUS_ADMIN_PORT)

G := \033[0;32m
Y := \033[0;33m
R := \033[0;31m
B := \033[0;34m
N := \033[0m

.DEFAULT_GOAL := help
.PHONY: help setup install-apps verify deps docker-build docker-start docker-stop \
        docker-restart docker-destroy fixtures sulu-install sulu-theme fixtures-fallback \
        assets test-checkout cache-clear db-reset info logs \
        shell-sylius shell-sulu doctor products-off products-on reset-soft git-init \
        payments-demo payments-live phpstan test test-integration test-smoke test-all

## --------------------------------------------------------------------------
## First install
## --------------------------------------------------------------------------

setup: ## Complete first install - runs through to a finished shop
	test -f .env.docker || cp .env.docker.example .env.docker
	$(MAKE) --no-print-directory docker-build
	$(MAKE) --no-print-directory install-apps
	$(MAKE) --no-print-directory docker-start
	$(MAKE) --no-print-directory deps
	$(MAKE) --no-print-directory verify
	$(MAKE) --no-print-directory fixtures
	$(MAKE) --no-print-directory test-checkout
	@printf "\n$(G)>> Done. Storefront: $(BASE)/produkte/$(N)\n"
# verify.sh exits with code 1 on any finding - make automatically stops the
# chain before "fixtures" in that case, and the verify output stays visible
# at the top of the terminal. No "ignore the warning and continue anyway".

install-apps: ## Set up Sylius (root) and Sulu (./sulu) - idempotent
	bash docker/scripts/install-apps.sh

verify: ## Check classes, service IDs and configuration - BEFORE fixtures
	bash docker/scripts/verify.sh

deps: ## Composer dependencies (without auto-scripts)
	@printf "$(B)>> Sylius: composer install$(N)\n"
	$(SYL) composer install --no-interaction --prefer-dist --no-scripts
	@printf "$(B)>> Sylius: cache:clear$(N)\n"
	$(CSYL) cache:clear
	@printf "$(B)>> Sulu: composer install$(N)\n"
	$(SULU) composer install --no-interaction --prefer-dist --no-scripts
	@printf "$(B)>> Sulu: cache:clear (90s timeout as a safety net)$(N)\n"
	@$(SULU) bash -c 'timeout 90 php -d memory_limit=1536M bin/console cache:clear --env=prod --no-debug' \
	  && printf "$(G)   Sulu cache OK$(N)\n" \
	  || printf "$(R)   Sulu cache failed or timed out - see make verify section 6$(N)\n"

## --------------------------------------------------------------------------
## Docker
## --------------------------------------------------------------------------

docker-build: ## Build the PHP image (native arm64 on Apple Silicon)
	$(DC) build --pull

docker-start: ## Start containers, wait for MySQL
	test -f .env.docker || cp .env.docker.example .env.docker
	$(DC) up -d --remove-orphans
	@printf "$(Y)Waiting for MySQL"
	@until $(DC) exec -T database mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1; do printf "."; sleep 2; done
	@printf " ready$(N)\n"
	@$(MAKE) --no-print-directory info

docker-stop: ## Stop containers
	$(DC) down

docker-restart: docker-stop docker-start ## Restart

docker-destroy: ## Remove containers AND volumes
	$(DC) down -v --remove-orphans

logs: ## Follow logs
	$(DC) logs -f --tail=100

shell-sylius: ## Shell inside the Sylius container
	$(DC) exec sylius bash

shell-sulu: ## Shell inside the Sulu container
	$(DC) exec sulu bash

## --------------------------------------------------------------------------
## Demo data - every step individually repeatable
## --------------------------------------------------------------------------

fixtures: ## Create DB, migrate, load DACH fixtures, build assets (Sylius + Sulu)
	@printf "$(B)>> Sylius: database$(N)\n"
	$(CSYL) doctrine:database:create --if-not-exists --no-interaction
	$(CSYL) doctrine:migrations:migrate --no-interaction --allow-no-migration
	@printf "$(B)>> Sylius: fixtures$(N)\n"
	$(CSYL) sylius:fixtures:load dach_demo --no-interaction
	@$(MAKE) --no-print-directory assets
	@$(MAKE) --no-print-directory sulu-install
	@printf "$(G)>> Done. Storefront: $(BASE)/produkte/  |  Sulu: $(BASE)/$(N)\n"

sulu-install: ## Sulu database + PHPCR + admin user + Sulu theme + demo content
	@$(MAKE) --no-print-directory sulu-theme
	@printf "$(B)>> Sulu: sulu:build dev (DB, schema, PHPCR, search index, admin/admin)$(N)\n"
	@printf "$(Y)   The only command needed, per https://docs.sulu.io/3.x/book/getting-started.html$(N)\n"
	$(SULU) bash -c 'php bin/adminconsole sulu:build dev --no-interaction'
	@printf "$(B)>> Sulu: composer autoload (for the freshly copied seed command)$(N)\n"
	$(SULU) bash -c 'composer dump-autoload --no-interaction'
	$(CSULU) cache:clear
	@printf "$(B)>> Sulu: filling the homepage with demo content (draft)$(N)\n"
	-$(CSULU) app:seed-homepage
	@printf "$(G)>> Sulu admin: $(BASE)/admin/  (admin / admin)$(N)\n"
	@printf "$(Y)>> One manual step left: open the homepage in the Sulu admin\n"
	@printf "   and publish it (content is already filled in).\n"
	@printf "   Details: README.md, Integration section.$(N)\n"

sulu-theme: ## Copy Sulu theme + Sylius bridge into ./sulu (idempotent)
	@test -d sulu-overlay || { printf "$(R)sulu-overlay/ is missing - package incomplete$(N)\n"; exit 1; }
	@test -d sulu || { printf "$(R)./sulu doesn't exist yet - run make install-apps first$(N)\n"; exit 1; }
	cp -r sulu-overlay/. sulu/
	@printf "$(G)>> Theme files copied (template, CSS, Sylius bridge)$(N)\n"
	-$(CSULU) cache:clear

fixtures-fallback: ## Emergency exit: Sylius' default fixtures instead of ours
	$(CSYL) doctrine:database:create --if-not-exists --no-interaction
	$(CSYL) doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(CSYL) sylius:fixtures:load default --no-interaction
	@$(MAKE) --no-print-directory assets

products-off: ## Disable the product fixture (shop runs without products)
	@test -f config/packages/dach_products.yaml \
	  && mv config/packages/dach_products.yaml config/packages/dach_products.yaml.disabled \
	  && printf "$(Y)Product fixture disabled$(N)\n" || printf "already off\n"
	$(CSYL) cache:clear

products-on: ## Re-enable the product fixture
	@test -f config/packages/dach_products.yaml.disabled \
	  && mv config/packages/dach_products.yaml.disabled config/packages/dach_products.yaml \
	  && printf "$(G)Product fixture enabled$(N)\n" || printf "already on\n"
	$(CSYL) cache:clear

payments-demo: ## Switch checkout to demo/offline payment methods (safe default, see FIXES.md No. 30)
	$(CSYL) doctrine:query:sql "UPDATE sylius_payment_method SET is_enabled = 1 WHERE code IN ('paypal','klarna_invoice','credit_card','invoice')"
	$(CSYL) doctrine:query:sql "UPDATE sylius_payment_method SET is_enabled = 0 WHERE code = 'adyen'"
	$(CSYL) cache:clear
	@printf "$(G)>> Demo mode: PayPal/Klarna/credit card (offline) + prepayment active, Adyen disabled.$(N)\n"

payments-live: ## Switch checkout to the real Adyen gateway (see FIXES.md No. 30)
	@grep -qE '^ADYEN_API_KEY=.+' .env.docker 2>/dev/null \
	  || { printf "$(R)ADYEN_API_KEY is empty in .env.docker - fill in real sandbox credentials first, then retry.$(N)\n"; exit 1; }
	@printf "$(Y)If you just filled in the Adyen credentials, recreate the container first so it\n"
	@printf "   picks up the new environment variables:\n"
	@printf "     docker compose -f docker-compose.yaml --env-file .env.docker up -d --force-recreate sylius$(N)\n"
	$(CSYL) doctrine:query:sql "UPDATE sylius_payment_method SET is_enabled = 0 WHERE code IN ('paypal','klarna_invoice','credit_card')"
	$(CSYL) doctrine:query:sql "UPDATE sylius_payment_method SET is_enabled = 1 WHERE code = 'adyen'"
	$(CSYL) cache:clear
	@printf "$(G)>> Live mode: Adyen active, prepayment stays active (it's a real payment method, not a demo), other demo methods disabled.$(N)\n"

assets: ## Build and install assets
	-$(SYL) yarn install
	-$(SYL) yarn build
	$(CSYL) assets:install public --symlink --relative

test-checkout: ## 5 completed test orders
	$(CSYL) app:create-test-orders --count=5

## --------------------------------------------------------------------------
## Operations
## --------------------------------------------------------------------------

cache-clear: ## Clear cache for both apps
	$(CSYL) cache:clear
	-$(CSULU) cache:clear

db-reset: ## Rebuild the Sylius database
	$(CSYL) doctrine:database:drop --force --if-exists
	$(CSYL) doctrine:database:create --no-interaction
	$(CSYL) doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(CSYL) sylius:fixtures:load dach_demo --no-interaction

reset-soft: ## Containers and volumes gone, code stays - for a fresh start
	$(DC) down -v --remove-orphans
	@printf "$(Y)Volumes deleted. Code and vendor/ stay.$(N)\n"
	@printf "Continue with: make docker-start && make deps && make verify\n"

git-init: ## Create a local Git repository with a clean initial commit (once)
	@if [ -d .git ]; then \
		printf "$(Y)Already a Git repository - .git/ exists, nothing done.$(N)\n"; \
		printf "To start over: rm -rf .git && make git-init\n"; \
	else \
		git init; \
		git add .; \
		git commit -m "Sulu & Sylius Kickstarter: initial state (headless, catalog verified)" -q; \
		printf "$(G)>> Repository created, initial commit made.$(N)\n"; \
		printf "$(Y)>> Does NOT include: vendor/ (both apps), var/, node_modules/,\n"; \
		printf "   .env.docker/.env.local (secrets) - see .gitignore.\n"; \
		printf "   DOES include: composer.json + composer.lock from both apps,\n"; \
		printf "   all custom code (config/packages/dach_*.yaml,\n"; \
		printf "   src/Fixture/, src/Command/, sulu-overlay/), the Docker setup,\n"; \
		printf "   documentation.$(N)\n"; \
		printf "   Details: README.md, \"For development teams\" section.\n"; \
	fi

doctor: ## Quick diagnosis while running
	@printf "$(B)Architecture:$(N) "; docker version --format '{{.Server.Arch}}'
	@printf "$(B)Sylius:$(N)\n"; -$(SYL) composer show --locked 2>/dev/null | grep -E "^sylius/sylius " || true
	@printf "$(B)Fixture suites:$(N)\n"; -$(CSYL) sylius:fixtures:list 2>/dev/null || true
	@printf "$(B)Payment methods:$(N)\n"; -$(CSYL) doctrine:query:sql "SELECT code,is_enabled FROM sylius_payment_method" 2>/dev/null || true
	@printf "$(B)Shipping methods:$(N)\n"; -$(CSYL) doctrine:query:sql "SELECT code,is_enabled FROM sylius_shipping_method" 2>/dev/null || true
	@printf "$(B)Products:$(N)\n"; -$(CSYL) doctrine:query:sql "SELECT code FROM sylius_product" 2>/dev/null || true

test: ## Run the unit test suite - no containers needed (see FIXES.md No. 46)
	@printf "$(B)>> Copying the overlay into ./sulu first - tests live there too$(N)\n"
	@$(MAKE) --no-print-directory sulu-theme
	@printf "$(B)>> Sulu side: unit tests for our own classes$(N)\n"
	$(SULU) vendor/bin/phpunit --testdox --exclude-group integration --exclude-group smoke
	@printf "$(G)>> Tests passed.$(N)\n"

test-integration: ## Run tests against the RUNNING Sylius API (see FIXES.md No. 47)
	@printf "$(B)>> Copying the overlay into ./sulu first$(N)\n"
	@$(MAKE) --no-print-directory sulu-theme
	@printf "$(B)>> Integration tests against the live Shop API$(N)\n"
# --fail-on-skipped: the tests skip themselves when the API is unreachable,
# which PHPUnit would otherwise report as a pass with nothing run. The || arm
# carries the prerequisite hint, so a successful run stays quiet. Wording
# covers a real test failure too - that also lands here.
	@$(SULU) vendor/bin/phpunit --testdox --fail-on-skipped --group integration \
	  || { printf "$(Y)   No test ran, or a test failed. Integration tests need\n"; \
	       printf "   running containers with fixtures loaded - try: make setup$(N)\n"; \
	       exit 1; }
	@printf "$(G)>> Integration tests passed.$(N)\n"

test-smoke: ## Check the storefront through Caddy: pages, elements, routing (see FIXES.md No. 49)
	@printf "$(B)>> Copying the overlay into ./sulu first$(N)\n"
	@$(MAKE) --no-print-directory sulu-theme
	@printf "$(B)>> Front-end smoke tests through the Caddy router$(N)\n"
# Same reasoning as test-integration above. The smoke tests do NOT need a
# published Sulu homepage - they only request controller routes, never "/".
	@$(SULU) vendor/bin/phpunit --testdox --fail-on-skipped --group smoke \
	  || { printf "$(Y)   No test ran, or a test failed. Smoke tests need running\n"; \
	       printf "   containers with fixtures loaded - try: make setup$(N)\n"; \
	       exit 1; }
	@printf "$(G)>> Smoke tests passed.$(N)\n"

test-all: ## Unit tests, integration tests and smoke tests
	@$(MAKE) --no-print-directory test
	@$(MAKE) --no-print-directory test-integration
	@$(MAKE) --no-print-directory test-smoke

phpstan: ## Static analysis of this project's own PHP code (see FIXES.md No. 40)
	@printf "$(B)>> Copying the overlay into ./sulu first - analysis runs on the copies$(N)\n"
	@$(MAKE) --no-print-directory sulu-theme
	@printf "$(B)>> Sylius side: src/Fixture + src/Command (level 5)$(N)\n"
	$(SYL) vendor/bin/phpstan analyse -c phpstan-kickstarter.dist.neon --no-progress
	@printf "$(B)>> Sulu side: src + tests (Sulu's own config, level max)$(N)\n"
	$(SULU) vendor/bin/phpstan analyse src tests --no-progress
	@printf "$(G)>> Static analysis clean.$(N)\n"

info: ## URLs and container status
	@printf "\n$(G)=== Sulu & Sylius Kickstarter ===$(N)\n\n"
	@printf "  Sulu frontend   $(BASE)/\n"
	@printf "  Sulu admin      $(BASE)/admin/  (admin / admin)\n"
	@printf "  Storefront      $(BASE)/produkte/\n"
	@printf "  Sylius admin    $(SYLIUS_ADMIN_BASE)/admin/  (admin@demo.de / admin)\n"
	@printf "  Mailpit         $(BASE)/mail/\n"
	@printf "  phpMyAdmin      http://localhost:8081/  (root / root)\n\n"
	@$(DC) ps 2>/dev/null || true
	@printf "\n"

help: ## This overview
	@printf "\n$(G)Sulu & Sylius Kickstarter$(N)\n\n"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'
	@printf "\n  $(Y)New here?$(N)  make setup\n"
	@printf "  $(Y)Stuck?$(N)  make verify\n\n"
