# Проверка второго этапа

Все команды работают в Windows CMD из каталога проекта. Предпочтительный
вариант — детерминированный runner: он сам создаёт отдельные товары, ключи и
заказы, управляет заглушками и проверяет PostgreSQL-инварианты.

## 1. Обновление и полный прогон

~~~console
docker compose down
docker compose up --build -d
docker compose ps
docker compose exec -T api vendor/bin/phpunit
docker compose exec -T api php tests/Integration/run-all.php
~~~

Ожидаемый итог: unit-suite завершается с OK, затем первый runner показывает
8 passed / 0 failed, второй — 5 passed / 0 failed. Сценарий аварии намеренно
перезапускает worker; API и тест продолжают работать.

Если миграционный контейнер не был пересоздан:

~~~console
docker compose run --rm migrate
~~~

## 2. Частичная выдача и деньги

Сценарий Multi-item partial fulfillment:

1. создаёт корзину из позиций 10000 и 20000 minor units у A и B;
2. A выдаёт код, B подтверждает отсутствие выдачи;
3. первая позиция остаётся delivered, вторая становится refunded;
4. повторяет тот же webhook и recovery;
5. проверяет одну выдачу, один возврат и две проводки.

Последний заказ:

~~~console
docker compose exec -T db psql -U game_store -d game_store -x -c "SELECT public_id,status,amount_minor FROM orders WHERE public_id LIKE 'ord_it_2_partial_%' ORDER BY created_at DESC LIMIT 1"
~~~

Проверка денег у конечных заказов:

~~~console
docker compose exec -T db psql -U game_store -d game_store -c "WITH x AS (SELECT o.public_id,o.status,COALESCE(SUM(l.amount_minor) FILTER(WHERE l.entry_type='capture'),0) paid,COALESCE(SUM(l.amount_minor) FILTER(WHERE l.entry_type='refund'),0) returned,COALESCE((SELECT SUM(oi.amount_minor) FROM order_items oi WHERE oi.order_id=o.id AND oi.status='delivered'),0) delivered FROM orders o LEFT JOIN ledger_entries l ON l.order_id=o.id WHERE o.public_id LIKE 'ord_it_2_%' GROUP BY o.id) SELECT *,paid=delivered+returned AS money_ok FROM x WHERE status IN('delivered','partially_refunded','refunded') ORDER BY public_id"
~~~

У каждой строки money_ok должно быть t.

## 3. Недобросовестный поставщик

Сценарий Untrusted provider проверяет повтор уже выданного кода, код чужого SKU
и HTTP 500 после фактической выдачи. В первых двух случаях покупатель получает
новый правильный код; в третьем повторной выдачи нет.

~~~console
docker compose exec -T db psql -U game_store -d game_store -x -c "SELECT o.public_id,pd.provider,pd.kind,pd.status,pd.resolution FROM provider_discrepancies pd JOIN orders o ON o.id=pd.order_id WHERE o.public_id LIKE 'ord_it_2_%' ORDER BY pd.id"
~~~

Ожидаются duplicate_code и foreign_sku со статусом resolved.

Проверка локальной уникальности:

~~~console
docker compose exec -T db psql -U game_store -d game_store -c "SELECT code,COUNT(*) FROM fulfillments GROUP BY code HAVING COUNT(*)>1"
~~~

Результат: 0 rows. Сам обман остаётся видим в reconciliation как collision
журнала поставщика.

## 4. Авария и перезапуск

Режим crash_after_issue_once выдаёт код, worker подтверждает его через audit и
завершается с кодом 70 до локального fulfillment. Compose перезапускает worker.
Через 5 секунд протухшая задача снова захватывается; новый процесс использует
прежний request_id и фиксирует ровно одну выдачу.

~~~console
docker compose ps
docker compose exec -T db psql -U game_store -d game_store -c "SELECT o.public_id,j.attempts,COUNT(DISTINCT pi.id) provider_issues,COUNT(DISTINCT f.id) fulfillments FROM orders o JOIN jobs j ON j.aggregate_id=o.public_id LEFT JOIN provider_issues pi ON pi.order_public_id=o.public_id LEFT JOIN fulfillments f ON f.order_id=o.id WHERE o.public_id LIKE 'ord_it_2_crash_%' GROUP BY o.public_id,j.attempts ORDER BY o.public_id DESC LIMIT 1"
~~~

Ожидается attempts не меньше 2, provider_issues = 1, fulfillments = 1.

## 5. Всплеск и rate limit

Runner ставит для A лимит 3 запроса / 2 секунды, быстро оплачивает девять
заказов и оставляет один неоплаченным. Он проверяет каждый скользящий
двухсекундный интервал, доставку всех девяти и отсутствие job/provider issue
для неоплаченного заказа.

~~~console
curl http://localhost:8080/api/v1/ops/queue-progress
~~~

## 6. История

Runner получает snapshot частичного заказа на момент создания, сравнивает
дельты 30000 = 10000 + 20000, затем напрямую пробует UPDATE order_events.
Триггер обязан вернуть ошибку order_events is append-only.

~~~console
docker compose exec -T db psql -U game_store -d game_store -x -c "SELECT event_type,order_status,captured_delta_minor,delivered_delta_minor,refunded_delta_minor,recorded_at FROM order_events WHERE order_public_id LIKE 'ord_it_2_partial_%' ORDER BY id DESC LIMIT 10"
~~~

## 7. Диагностика

~~~console
curl http://localhost:8080/api/v1/ops/reconciliation
docker compose logs --tail 100 worker
docker compose logs --tail 100 recovery
~~~

Audit endpoint является частью эмулятора независимого settlement-контракта
поставщика. Без такого источника истины проверить работоспособность произвольной
непрозрачной строки-кода принципиально невозможно.
