# Game Store Core

Backend маркетплейса цифровых товаров на PHP 8.3 и PostgreSQL. Версия 2.0
расширяет совместимый первый этап: один заказ содержит до 50 товаров, допускает
частичную выдачу и автоматические возвраты, проверяет недобросовестного
поставщика, выдерживает лимит запросов и восстанавливает состояние заказа на
любой прошлый момент.

PostgreSQL хранит данные, надёжную очередь, распределённый rate limiter и
append-only историю. Фронтенд, реальная оплата и реальные поставщики не нужны.

## Запуск или обновление с первого этапа

Требуется Docker Desktop с Compose v2. Локальные PHP, Composer, WSL, Git Bash и
make не нужны.

~~~console
docker compose down
docker compose up --build -d
docker compose ps
~~~

Команда down не удаляет volume базы. При следующем up сервис migrate применит
004_advanced_orders.sql, создаст legacy-позиции для прежних заказов и сохранит
данные первого этапа. Не используйте down -v, если данные нужно сохранить.

Если порт 8080 занят, оставьте APP_PORT=8081 в .env. Внутренние адреса
PROVIDER_A_URL и PROVIDER_B_URL должны использовать http://api:8080.

## Полная автоматическая приёмка

~~~console
docker compose exec -T api vendor/bin/phpunit
docker compose exec -T api php tests/Integration/run-all.php
~~~

run-all.php последовательно выполняет:

- 8 регрессионных сценариев первого этапа;
- 5 сценариев второго этапа: частичная выдача/возврат, три вида обмана
  поставщика, авария и рестарт worker, всплеск с жёстким rate limit,
  point-in-time и запрет изменения истории.

Можно запускать раздельно:

~~~console
docker compose exec -T api php tests/Integration/run.php
docker compose exec -T api php tests/Integration/stage2.php
~~~

Тестовые данные изолированы префиксами ord_it_ и ITEST-. Текущий прогон
сохраняется для SQL-разбора; следующий удаляет только эти фикстуры.
Исторические события принципиально не удаляются.

## Совместимый однотоварный запрос

~~~console
curl -X POST http://localhost:8080/api/v1/orders -H "Content-Type: application/json" -d "{\"order_id\":\"ord_demo_001\",\"sku\":\"STEAM-TOPUP-500\"}"
~~~

Контракт работает как в первом этапе: основной поставщик A и безопасный
fallback B. Старые статусы и верхнеуровневое поле delivery сохранены.

## Заказ из нескольких товаров

~~~json
POST /api/v1/orders
{
  "order_id": "ord_demo_multi_001",
  "items": [
    {"sku": "STEAM-TOPUP-500", "provider": "A"},
    {"sku": "KEY-GTA5", "provider": "B", "allow_fallback": false}
  ]
}
~~~

Цена берётся только из каталога. После webhook на общую сумму каждая позиция
обрабатывается независимо. Если позицию multi-item заказа нельзя выдать,
создаются уникальные refunds и ledger_entries; успешные позиции не откатываются.

GET /api/v1/orders/{id} возвращает позиции и проверяемое равенство:

~~~text
paid_minor = delivered_minor + refunded_minor + outstanding_minor
~~~

В конечном состоянии outstanding_minor равен нулю.

## Недоверенный поставщик

Начальный POST /issue не считается доказательством. Worker читает независимый
settlement endpoint GET /issues/{request_id} и проверяет заказ, запрошенный и
фактический SKU, совпадение ответа с журналом, карантин и предыдущие выдачи.

Глобальный UNIQUE для fulfillments.code защищает последнюю гонку. Подозрительная
попытка записывается в provider_discrepancies, код — в quarantined_codes, затем
создаётся repair-request. После правильной замены расхождение автоматически
получает статус resolved.

Dev-режимы заглушки:

- error_after_issue_once — HTTP 500 после реальной выдачи;
- duplicate_code_once — код, уже выданный другому заказу;
- foreign_code_once — код другого SKU;
- crash_after_issue_once — завершение worker между внешней и локальной записью;
- прежние success, fail_before_issue, timeout_after_issue, out_of_stock, random.

## Всплеск и прогресс

provider_request_log и advisory lock реализуют единый скользящий лимит для
любого количества worker-процессов. При исчерпании окна задача возвращается в
очередь до точного времени освобождения. Неоплаченный заказ не допускается в
очередь выдачи, поэтому не занимает место перед оплаченным и не расходует лимит.

~~~console
curl http://localhost:8080/api/v1/ops/queue-progress
~~~

## История на момент времени

Каждое бизнес-изменение в той же транзакции добавляет полный snapshot в
order_events. PostgreSQL trigger запрещает UPDATE и DELETE.

~~~text
GET /api/v1/orders/{id}/state-at?at=2026-09-10T12:00:00Z
GET /api/v1/ops/history-summary?from=2026-09-01T00:00:00Z&to=2026-10-01T00:00:00Z
~~~

Итоги периода считаются из денежных дельт immutable-событий, а не из текущих
строк заказа.

## Документы

- [Пошаговая проверка второго этапа](docs/stage2-testing.md)
- [Архитектура и гарантии](docs/architecture.md)
- [Матрица приёмки](docs/acceptance.md)
- [OpenAPI 3.1](openapi.yaml)
- [Фактическое время](docs/time-log.md)

## Фактически затраченное время

Первый этап — около **6 часов**, второй — около **5 часов**. Суммарно — около
**11 часов**, включая проектирование, реализацию, аварийные/конкурентные
сценарии и документацию. При разработке использовался AI-ассистент;
архитектурные решения, проверка инвариантов и приёмка остаются частью
выполненной инженерной работы.
