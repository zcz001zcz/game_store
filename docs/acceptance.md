# Матрица приёмки

## Второй этап

| Требование | Реализация | Автоматическая проверка |
| --- | --- | --- |
| Несколько товаров и разные поставщики | order_items со snapshot provider/цены | stage2: partial basket A+B |
| Частичная выдача | независимый commit позиции + refund невыданной | delivered + refunded в одном заказе |
| Оплачено = выдано + возвращено | capture/refund ledger и money projection | 30000 = 10000 + 20000 |
| Без лишних повторов | PK/UNIQUE + idempotency keys | повтор webhook и recovery |
| Авария и restart | lease очереди + стабильный request_id | worker exit 70, restart, одна выдача |
| Дубль кода поставщика | audit, карантин, UNIQUE fulfillment code | duplicate_code_once |
| Чужой код | requested_sku против actual_sku | foreign_code_once |
| Ошибка после выдачи | audit на неуспешном ответе | error_after_issue_once, один issue |
| Авторазбор расхождений | repair generation, resolved discrepancy | обе аномалии закрыты автоматически |
| Очередь при лимите | PostgreSQL sliding-window limiter | 9 заказов при 3/2s |
| Лимит не превышен | advisory lock + request log | проверка каждого скользящего окна |
| Оплаченные первыми | unpaid не допускается в delivery queue | у unpaid нет job и provider issue |
| Видимый прогресс | GET /ops/queue-progress | проверка полей до/после burst |
| Состояние на дату | immutable snapshots | состояние created до оплаты |
| Нельзя переписать историю | trigger UPDATE/DELETE reject | прямой UPDATE обязан упасть |
| Итоги периода сходятся | суммы event deltas | capture = delivery + refund |

Команда:

~~~console
docker compose exec -T api php tests/Integration/stage2.php
~~~

## Регрессия первого этапа

Сохраняются все восемь сценариев: каталог 2000 SKU под 50 конкурентными
чтениями, 100 webhook, fallback, uncertain timeout, out-of-stock recovery,
background recovery, ранние/переставленные webhook и четыре класса
reconciliation.

~~~console
docker compose exec -T api php tests/Integration/run.php
~~~

Обе части одной командой:

~~~console
docker compose exec -T api php tests/Integration/run-all.php
~~~

До пользовательского Docker-прогона выполнена статическая проверка синтаксиса
всех PHP-файлов и разбор OpenAPI YAML. Фактический runtime-результат следует
фиксировать из вывода этих команд.
