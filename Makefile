.PHONY: local-build local-up local-down local-dev

local-build:
	./vendor/bin/sail build --no-cache

local-up:
	./vendor/bin/sail up -d

local-down:
	./vendor/bin/sail down

local-dev: local-up
	./vendor/bin/sail artisan dev
