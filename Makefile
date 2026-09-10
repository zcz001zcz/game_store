.PHONY: up down logs migrate test test-race test-fallback test-timeout test-stock test-ordering test-integration test-stage2 test-all test-integration-shell worker-once

up:
	docker compose up --build -d

down:
	docker compose down

logs:
	docker compose logs -f api worker

migrate:
	docker compose run --rm migrate

test:
	docker compose exec -T api vendor/bin/phpunit

test-race:
	sh tests/Integration/race.sh

test-fallback:
	sh tests/Integration/fallback.sh

test-timeout:
	sh tests/Integration/timeout_recovery.sh

test-stock:
	sh tests/Integration/out_of_stock_recovery.sh

test-ordering:
	sh tests/Integration/webhook_ordering.sh

test-integration:
	docker compose exec -T api php tests/Integration/run.php

test-stage2:
	docker compose exec -T api php tests/Integration/stage2.php

test-all:
	docker compose exec -T api php tests/Integration/run-all.php

test-integration-shell: test-race test-fallback test-timeout test-stock test-ordering

worker-once:
	docker compose exec -T worker php bin/worker-once.php
