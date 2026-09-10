# Архитектура и гарантии

## Транзакционная модель

Сеть даёт at-least-once доставку, поэтому exactly-once реализован как эффект
домена. Критические ограничения находятся в PostgreSQL и не зависят от числа
API/worker-процессов.

| Инвариант | Механизм |
| --- | --- |
| Один платёжный event | PK payment_events.event_id |
| Одна capture на заказ | partial UNIQUE ledger_entries(order_id) |
| Один refund на позицию | UNIQUE refunds.order_item_id и ledger idempotency_key |
| Одна задача на заказ | UNIQUE jobs.dedup_key |
| Одна выдача на позицию | UNIQUE fulfillments.order_item_id |
| Один код одному покупателю | глобальный UNIQUE fulfillments.code |
| Один внешний эффект запроса | UNIQUE provider_issues(provider, request_id) |
| Одна попытка поколения | UNIQUE delivery_attempts(item, provider, generation) |

Payment webhook в одной транзакции фиксирует event, переводит заказ в paid,
создаёт capture и job. Неоплаченный заказ в delivery queue не попадает.

## Multi-item и деньги

orders — агрегат, order_items — независимо выдаваемые позиции со snapshot цены,
валюты и назначенного поставщика. Успешная позиция сразу коммитится и больше не
откатывается. Подтверждённый окончательный отказ новой позиции в той же
транзакции создаёт refund и refund-проводку.

Для любого оплаченного состояния:

~~~text
captured = delivered + refunded + outstanding
~~~

Для delivered, partially_refunded и refunded outstanding равен нулю. Все суммы
— BIGINT в минимальных единицах; float в расчётах не используется.

Legacy-запрос с одним sku сохраняет восстановимые out_of_stock/delivery_failed
и fallback A → B первого этапа. Новый items-контракт по умолчанию закрепляет
позицию за указанным поставщиком; allow_fallback включается явно.

## Недоверенный поставщик

Ответ issue — только claim, а не источник истины. После каждого определённого
ответа worker читает отдельный settlement/audit endpoint по request_id. Он
сверяет:

1. заказ и requested SKU;
2. actual SKU зарезервированного кода;
3. код ответа и код settlement;
4. карантин и локальные fulfillments.

При duplicate/foreign/missing claim попытка становится invalid_claim,
создаётся provider_discrepancy, код карантинится и следующее детерминированное
поколение request_id запрашивает замену. Успешная замена закрывает расхождение.
Даже при одновременной гонке глобальный UNIQUE для fulfillment code не позволяет
двум транзакциям закоммитить один код.

HTTP 500 не доказывает отсутствие выдачи: audit может найти уже созданный код.
Сетевой timeout остаётся uncertain и сначала повторяется с прежним request_id.
Fallback допустим только после audit not_found.

Гарантия корректности непрозрачного кода требует независимого settlement или
validate-контракта поставщика. Без него математически невозможно отличить
рабочую строку от ложной; именно этот контракт эмулирует stub audit endpoint.

## Авария между внешним и локальным commit

Провайдер сначала фиксирует issue, после чего приложение сохраняет fulfillment.
Если процесс погиб в этом промежутке, job остаётся processing с lease.
После QUEUE_STALE_AFTER_SECONDS другой процесс захватывает job. Детерминированный
request_id возвращает прежний provider issue, audit подтверждает его, а
уникальные ограничения позволяют закоммитить один fulfillment.

Режим crash_after_issue_once воспроизводит именно этот разрыв: worker выходит с
кодом 70, Compose перезапускает контейнер, тест ждёт истечения пятсекундного
lease.

## Очередь и rate limit

jobs резервируется через FOR UPDATE SKIP LOCKED и сортируется по priority,
available_at, id. На практике только paid-заказы получают job, поэтому
неоплаченные не конкурируют с ними вообще.

ProviderRateLimiter берёт PostgreSQL advisory transaction lock на поставщика,
считает provider_request_log в скользящем окне и записывает разрешённый запрос
до HTTP-вызова. Все worker используют одну сериализованную точку решения.
Если лимит исчерпан, job возвращается точно к моменту освобождения окна.
Такое плановое ожидание уменьшает счётчик attempts обратно и не расходует
retry budget, поэтому даже очередь длиннее max_attempts не теряет заказы.

GET /api/v1/ops/queue-progress показывает queued/processing/completed/dead,
waiting/delivered/refunded items и число неоплаченных, не допущенных в очередь.

## Append-only история

OrderEventStore вызывается внутри той же транзакции, что и бизнес-изменение.
Каждая запись содержит полный snapshot заказа/позиций/денег и отдельные денежные
дельты. UNIQUE idempotency_key не позволяет повтору добавить вторую дельту.

Trigger order_events_append_only запрещает UPDATE и DELETE. Point-in-time
выбирает последнее событие не позже даты. Итоги периода суммируют immutable
captured/delivered/refunded deltas, а не текущее состояние orders.

## Каталог и эксплуатация

Каталог использует keyset pagination по id и покрывающий индекс. Доступный
остаток читает partial index по невыданным provider_stock. PostgreSQL-очередь
уменьшает инфраструктуру и атомарна с платежом; при росте её можно заменить
outbox + Kafka/RabbitMQ, сохранив idempotency keys consumers.

API, worker и stubs пишут JSON Lines без самих цифровых кодов. Reconciliation
показывает старые четыре класса аномалий, нарушения денежного равенства,
открытые provider discrepancies и повторные коды в журнале поставщика.
