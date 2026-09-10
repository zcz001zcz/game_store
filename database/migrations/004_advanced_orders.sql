-- Stage 2: multi-item fulfillment, refunds, untrusted providers, rate limiting
-- and append-only point-in-time history. The migration is additive and backfills
-- every stage-1 order with one legacy item.

ALTER TABLE orders DROP CONSTRAINT orders_status_check;
ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN (
    'created', 'paid', 'delivering', 'delivered', 'payment_failed',
    'out_of_stock', 'delivery_failed', 'partially_refunded', 'refunded'
));
ALTER TABLE orders ADD COLUMN request_fingerprint CHAR(64);
ALTER TABLE orders ADD COLUMN item_count INTEGER NOT NULL DEFAULT 1 CHECK (item_count BETWEEN 1 AND 50);

CREATE TABLE order_items (
    id UUID PRIMARY KEY,
    order_id UUID NOT NULL REFERENCES orders (id),
    line_no INTEGER NOT NULL CHECK (line_no > 0),
    product_id BIGINT NOT NULL REFERENCES products (id),
    sku VARCHAR(100) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    amount_minor BIGINT NOT NULL CHECK (amount_minor >= 0),
    currency CHAR(3) NOT NULL,
    provider CHAR(1) NOT NULL CHECK (provider IN ('A', 'B')),
    allow_fallback BOOLEAN NOT NULL DEFAULT FALSE,
    refund_on_failure BOOLEAN NOT NULL DEFAULT TRUE,
    status VARCHAR(24) NOT NULL DEFAULT 'pending' CHECK (status IN (
        'pending', 'delivering', 'delivered', 'out_of_stock', 'delivery_failed', 'refunded'
    )),
    delivery_code VARCHAR(100),
    delivered_at TIMESTAMPTZ,
    failure_kind VARCHAR(64),
    version INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (order_id, line_no)
);

CREATE UNIQUE INDEX uq_order_items_delivery_code
    ON order_items (delivery_code) WHERE delivery_code IS NOT NULL;
CREATE INDEX idx_order_items_order_status ON order_items (order_id, status, line_no);

INSERT INTO order_items (
    id, order_id, line_no, product_id, sku, product_name, amount_minor, currency,
    provider, allow_fallback, refund_on_failure, status, delivery_code,
    delivered_at, failure_kind, created_at, updated_at
)
SELECT (
           SUBSTRING(md5(o.id::text || ':legacy-item') FROM 1 FOR 8) || '-' ||
           SUBSTRING(md5(o.id::text || ':legacy-item') FROM 9 FOR 4) || '-' ||
           SUBSTRING(md5(o.id::text || ':legacy-item') FROM 13 FOR 4) || '-' ||
           SUBSTRING(md5(o.id::text || ':legacy-item') FROM 17 FOR 4) || '-' ||
           SUBSTRING(md5(o.id::text || ':legacy-item') FROM 21 FOR 12)
       )::uuid,
       o.id, 1, o.product_id, o.sku, o.product_name, o.amount_minor, o.currency,
       COALESCE((SELECT f.provider FROM fulfillments f WHERE f.order_id = o.id LIMIT 1), 'A'),
       TRUE, FALSE,
       CASE o.status
           WHEN 'delivered' THEN 'delivered'
           WHEN 'delivering' THEN 'delivering'
           WHEN 'out_of_stock' THEN 'out_of_stock'
           WHEN 'delivery_failed' THEN 'delivery_failed'
           ELSE 'pending'
       END,
       o.delivery_code, o.delivered_at,
       CASE WHEN o.status IN ('out_of_stock', 'delivery_failed') THEN o.status ELSE NULL END,
       o.created_at, o.updated_at
FROM orders o
ON CONFLICT (order_id, line_no) DO NOTHING;

ALTER TABLE delivery_attempts ADD COLUMN order_item_id UUID REFERENCES order_items (id);
ALTER TABLE delivery_attempts ADD COLUMN generation INTEGER NOT NULL DEFAULT 1 CHECK (generation BETWEEN 1 AND 10);
UPDATE delivery_attempts da
SET order_item_id = oi.id
FROM order_items oi
WHERE oi.order_id = da.order_id AND oi.line_no = 1 AND da.order_item_id IS NULL;
ALTER TABLE delivery_attempts ALTER COLUMN order_item_id SET NOT NULL;
ALTER TABLE delivery_attempts DROP CONSTRAINT delivery_attempts_order_id_provider_key;
ALTER TABLE delivery_attempts ADD CONSTRAINT uq_delivery_attempt_item_provider_generation
    UNIQUE (order_item_id, provider, generation);
CREATE INDEX idx_delivery_attempts_item ON delivery_attempts (order_item_id, provider, generation);

ALTER TABLE fulfillments ADD COLUMN order_item_id UUID REFERENCES order_items (id);
UPDATE fulfillments f
SET order_item_id = oi.id
FROM order_items oi
WHERE oi.order_id = f.order_id AND oi.line_no = 1 AND f.order_item_id IS NULL;
ALTER TABLE fulfillments ALTER COLUMN order_item_id SET NOT NULL;
ALTER TABLE fulfillments DROP CONSTRAINT fulfillments_order_id_key;
ALTER TABLE fulfillments ADD CONSTRAINT uq_fulfillments_order_item UNIQUE (order_item_id);

ALTER TABLE provider_issues ALTER COLUMN stock_id DROP NOT NULL;
ALTER TABLE provider_issues ADD COLUMN actual_sku VARCHAR(100);
ALTER TABLE provider_issues ADD COLUMN issue_kind VARCHAR(32) NOT NULL DEFAULT 'honest';
UPDATE provider_issues SET actual_sku = sku WHERE actual_sku IS NULL;
ALTER TABLE provider_issues ALTER COLUMN actual_sku SET NOT NULL;
ALTER TABLE provider_issues DROP CONSTRAINT provider_issues_stock_id_key;
ALTER TABLE provider_issues DROP CONSTRAINT provider_issues_code_key;
CREATE INDEX idx_provider_issues_code ON provider_issues (provider, code);

ALTER TABLE stub_provider_settings DROP CONSTRAINT stub_provider_settings_mode_check;
ALTER TABLE stub_provider_settings ADD CONSTRAINT stub_provider_settings_mode_check CHECK (mode IN (
    'random', 'success', 'fail_before_issue', 'timeout_after_issue', 'out_of_stock',
    'error_after_issue_once', 'duplicate_code_once', 'foreign_code_once', 'crash_after_issue_once'
));
ALTER TABLE stub_provider_settings ADD COLUMN rate_limit INTEGER NOT NULL DEFAULT 60 CHECK (rate_limit BETWEEN 1 AND 10000);
ALTER TABLE stub_provider_settings ADD COLUMN rate_window_seconds INTEGER NOT NULL DEFAULT 60
    CHECK (rate_window_seconds BETWEEN 1 AND 3600);

ALTER TABLE jobs ADD COLUMN priority INTEGER NOT NULL DEFAULT 100 CHECK (priority BETWEEN 0 AND 1000);
DROP INDEX idx_jobs_reserve;
CREATE INDEX idx_jobs_reserve ON jobs (priority DESC, available_at, id) WHERE status = 'queued';

ALTER TABLE ledger_entries ALTER COLUMN event_id DROP NOT NULL;
ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_entry_type_check;
ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_entry_type_check
    CHECK (entry_type IN ('capture', 'refund'));
ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_order_id_entry_type_key;
ALTER TABLE ledger_entries ADD COLUMN order_item_id UUID REFERENCES order_items (id);
ALTER TABLE ledger_entries ADD COLUMN idempotency_key VARCHAR(190);
UPDATE ledger_entries SET idempotency_key = 'capture:' || order_id::text WHERE idempotency_key IS NULL;
ALTER TABLE ledger_entries ALTER COLUMN idempotency_key SET NOT NULL;
ALTER TABLE ledger_entries ADD CONSTRAINT uq_ledger_idempotency_key UNIQUE (idempotency_key);
CREATE UNIQUE INDEX uq_ledger_capture_per_order ON ledger_entries (order_id) WHERE entry_type = 'capture';
CREATE UNIQUE INDEX uq_ledger_refund_per_item ON ledger_entries (order_item_id) WHERE entry_type = 'refund';

CREATE TABLE refunds (
    id BIGSERIAL PRIMARY KEY,
    order_id UUID NOT NULL REFERENCES orders (id),
    order_item_id UUID NOT NULL UNIQUE REFERENCES order_items (id),
    idempotency_key VARCHAR(190) NOT NULL UNIQUE,
    amount_minor BIGINT NOT NULL CHECK (amount_minor >= 0),
    currency CHAR(3) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'completed' CHECK (status IN ('completed')),
    reason VARCHAR(100) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_refunds_order ON refunds (order_id, created_at);

CREATE TABLE provider_discrepancies (
    id BIGSERIAL PRIMARY KEY,
    order_id UUID NOT NULL REFERENCES orders (id),
    order_item_id UUID NOT NULL REFERENCES order_items (id),
    delivery_attempt_id BIGINT REFERENCES delivery_attempts (id),
    provider CHAR(1) NOT NULL CHECK (provider IN ('A', 'B')),
    request_id VARCHAR(100) NOT NULL,
    kind VARCHAR(64) NOT NULL,
    reported_code VARCHAR(100),
    expected_sku VARCHAR(100) NOT NULL,
    actual_sku VARCHAR(100),
    status VARCHAR(16) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'resolved')),
    resolution VARCHAR(255),
    detected_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMPTZ,
    UNIQUE (request_id, kind)
);
CREATE INDEX idx_provider_discrepancies_open
    ON provider_discrepancies (status, detected_at) WHERE status = 'open';

CREATE TABLE quarantined_codes (
    provider CHAR(1) NOT NULL CHECK (provider IN ('A', 'B')),
    code VARCHAR(100) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    first_request_id VARCHAR(100) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (provider, code)
);

CREATE TABLE provider_request_log (
    id BIGSERIAL PRIMARY KEY,
    provider CHAR(1) NOT NULL CHECK (provider IN ('A', 'B')),
    request_id VARCHAR(100) NOT NULL,
    requested_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX idx_provider_request_log_window ON provider_request_log (provider, requested_at DESC);

CREATE TABLE order_events (
    id BIGSERIAL PRIMARY KEY,
    order_id UUID NOT NULL,
    order_public_id VARCHAR(80) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL UNIQUE,
    order_status VARCHAR(32) NOT NULL,
    captured_delta_minor BIGINT NOT NULL DEFAULT 0,
    delivered_delta_minor BIGINT NOT NULL DEFAULT 0,
    refunded_delta_minor BIGINT NOT NULL DEFAULT 0,
    snapshot JSONB NOT NULL,
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX idx_order_events_point_in_time
    ON order_events (order_public_id, recorded_at DESC, id DESC);
CREATE INDEX idx_order_events_period ON order_events (recorded_at, id);

CREATE FUNCTION reject_order_event_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'order_events is append-only' USING ERRCODE = '55000';
    RETURN OLD;
END;
$$;

CREATE TRIGGER order_events_append_only
BEFORE UPDATE OR DELETE ON order_events
FOR EACH ROW EXECUTE FUNCTION reject_order_event_mutation();

-- A baseline snapshot for orders predating this migration. New events contain richer
-- item/ledger snapshots and are written by the application in the same transaction
-- as the state change.
INSERT INTO order_events (
    order_id, order_public_id, event_type, idempotency_key, order_status,
    captured_delta_minor, delivered_delta_minor, refunded_delta_minor, snapshot, recorded_at
)
SELECT o.id, o.public_id, 'migration.baseline', 'migration-baseline:' || o.id::text, o.status,
       COALESCE((
           SELECT SUM(l.amount_minor) FROM ledger_entries l
           WHERE l.order_id = o.id AND l.entry_type = 'capture'
       ), 0),
       COALESCE((
           SELECT SUM(oi.amount_minor) FROM order_items oi
           WHERE oi.order_id = o.id AND oi.status = 'delivered'
       ), 0),
       0,
       jsonb_build_object(
           'id', o.public_id,
           'status', o.status,
           'currency', trim(o.currency),
           'amount_minor', o.amount_minor,
           'money', jsonb_build_object(
               'captured_minor', COALESCE((
                   SELECT SUM(l.amount_minor) FROM ledger_entries l
                   WHERE l.order_id = o.id AND l.entry_type = 'capture'
               ), 0),
               'delivered_minor', COALESCE((
                   SELECT SUM(oi.amount_minor) FROM order_items oi
                   WHERE oi.order_id = o.id AND oi.status = 'delivered'
               ), 0),
               'refunded_minor', 0
           ),
           'migrated_from_stage1', TRUE
       ),
       clock_timestamp()
FROM orders o
ON CONFLICT (idempotency_key) DO NOTHING;
