# Матрица приёмки

| Критерий задания | Реализация | Воспроизведение |
| --- | --- | --- |
| Каталог под параллельным чтением | keyset pagination, покрывающий и частичные индексы | Docker-runner: 2000 временных SKU, 50 одновременных чтений страницы по 100 товаров |
| 50 параллельных `paid` → одна выдача | блокировка заказа, уникальные capture/job/fulfillment | Docker-runner: race, разные `event_id` |
| Повтор того же `event_id` ничего не меняет | PK `payment_events.event_id`, `ON CONFLICT DO NOTHING` | Docker-runner: race, один `event_id` |
| Вебхук раньше заказа или не по порядку | pending events + advisory lock агрегата + replay при создании | Docker-runner: ordering |
| Таймаут после фактической выдачи | `uncertain`, прежний provider и стабильный `request_id` | Docker-runner: timeout |
| A недоступен → B | fallback только после определённого отказа до выдачи | Docker-runner: fallback |
| Пустой остаток | `out_of_stock`, reset определённых попыток и безопасный requeue | Docker-runner: stock recovery |
| Зависший оплаченный заказ | периодический recovery, reset только определённых отказов, тот же dedup key | Docker-runner: `delivery_failed → delivered` без ручного endpoint |
| Сверка состояния | четыре выборки: невыданная оплата, выдача без оплаты, ledger mismatch, pending event | Docker-runner создаёт, обнаруживает и удаляет четыре контролируемые аномалии |

Дополнительные пункты:

- сверка: `GET /api/v1/ops/reconciliation`, автоматически проверяется по всем четырём секциям;
- ручное восстановление: `POST /api/v1/ops/recovery`;
- фоновое восстановление: сервис `recovery` в Compose, проверяется без вызова ручного endpoint;
- журнал денег: `ledger_entries`, одна capture-проводка на заказ;
- структурированные JSON-логи: API, worker и поставщики;
- горячий каталог: keyset pagination и частичные/покрывающие индексы.

Кроссплатформенная команда полной приёмки:

```bash
docker compose exec -T api php tests/Integration/run.php
```

## Подтверждённый прогон

Последняя полная проверка выполнена 2026-09-07 на PHP 8.3.33 и PostgreSQL 16:

- PHPUnit 11.5.56: **16 тестов, 25 проверок, ошибок нет**;
- интеграционный runner: **8 сценариев из 8, ошибок нет**, 20.56 секунды;
- нагрузочная фикстура: 2000 SKU, 50 параллельных чтений по 100 товаров;
- фоновое восстановление: `delivery_failed → delivered` за 13.08 секунды;
- контролируемые аномалии сверки обнаружены во всех четырёх секциях и удалены.
