.PHONY: setup serve test lint queue fresh up down logs shell

## Local (no Docker): install, prepare .env, migrate
setup:
	composer install
	@[ -f .env ] || cp .env.example .env
	@php artisan key:generate --ansi
	@touch database/database.sqlite
	php artisan migrate

serve:
	php artisan serve

## Runs compression jobs; the API works without it, images just stay "pending"
queue:
	php artisan queue:work --queue=images,default

test:
	php artisan test

lint:
	./vendor/bin/pint

fresh:
	php artisan migrate:fresh

## Docker
up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f app worker

shell:
	docker compose exec app sh
