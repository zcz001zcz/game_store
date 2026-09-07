CREATE TABLE products (
    id BIGSERIAL PRIMARY KEY,
    sku VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(32) NOT NULL CHECK (type IN ('topup', 'key', 'subscription', 'giftcard')),
    price_minor BIGINT NOT NULL CHECK (price_minor >= 0),
    currency CHAR(3) NOT NULL,
    image_path VARCHAR(255),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_products_active_catalog
    ON products (is_active, id)
    INCLUDE (sku, name, type, price_minor, currency, image_path);

CREATE TABLE orders (
    id UUID PRIMARY KEY,
    public_id VARCHAR(80) NOT NULL UNIQUE,
    product_id BIGINT NOT NULL REFERENCES products (id),
    sku VARCHAR(100) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    amount_minor BIGINT NOT NULL CHECK (amount_minor >= 0),
    currency CHAR(3) NOT NULL,
    status VARCHAR(32) NOT NULL CHECK (status IN (
        'created',
        'paid',
        'delivering',
        'delivered',
        'payment_failed',
        'out_of_stock',
        'delivery_failed'
    )),
    payment_confirmed_at TIMESTAMPTZ,
    delivered_at TIMESTAMPTZ,
    delivery_code VARCHAR(100),
    version INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_orders_status_updated ON orders (status, updated_at);
CREATE UNIQUE INDEX uq_orders_delivery_code
    ON orders (delivery_code)
    WHERE delivery_code IS NOT NULL;

CREATE TABLE payment_events (
    event_id VARCHAR(120) PRIMARY KEY,
    order_public_id VARCHAR(80) NOT NULL,
    status VARCHAR(16) NOT NULL CHECK (status IN ('paid', 'failed')),
    amount_minor BIGINT NOT NULL CHECK (amount_minor >= 0),
    currency CHAR(3) NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    payload JSONB NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    processing_state VARCHAR(16) NOT NULL DEFAULT 'pending'
        CHECK (processing_state IN ('pending', 'applied', 'ignored', 'invalid')),
    processing_note VARCHAR(255),
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    processed_at TIMESTAMPTZ
);

CREATE INDEX idx_payment_events_pending_order
    ON payment_events (order_public_id, occurred_at, event_id)
    WHERE processing_state = 'pending';
CREATE INDEX idx_payment_events_order_received
    ON payment_events (order_public_id, received_at);

CREATE TABLE ledger_entries (
    id BIGSERIAL PRIMARY KEY,
    order_id UUID NOT NULL REFERENCES orders (id),
    event_id VARCHAR(120) NOT NULL REFERENCES payment_events (event_id),
    entry_type VARCHAR(16) NOT NULL CHECK (entry_type IN ('capture')),
    amount_minor BIGINT NOT NULL CHECK (amount_minor >= 0),
    currency CHAR(3) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (order_id, entry_type),
    UNIQUE (event_id)
);

CREATE INDEX idx_ledger_entries_order ON ledger_entries (order_id, created_at);

CREATE TABLE jobs (
    id BIGSERIAL PRIMARY KEY,
    type VARCHAR(80) NOT NULL,
    aggregate_id VARCHAR(100) NOT NULL,
    dedup_key VARCHAR(190) NOT NULL UNIQUE,
    payload JSONB NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued', 'processing', 'completed', 'dead')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    max_attempts INTEGER NOT NULL DEFAULT 12 CHECK (max_attempts > 0),
    available_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    locked_at TIMESTAMPTZ,
    locked_by VARCHAR(100),
    last_error TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_jobs_reserve
    ON jobs (available_at, id)
    WHERE status = 'queued';
CREATE INDEX idx_jobs_stale
    ON jobs (locked_at)
    WHERE status = 'processing';

CREATE TABLE delivery_attempts (
    id BIGSERIAL PRIMARY KEY,
    order_id UUID NOT NULL REFERENCES orders (id),
    provider CHAR(1) NOT NULL CHECK (provider IN ('A', 'B')),
    request_id VARCHAR(100) NOT NULL UNIQUE,
    status VARCHAR(24) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'uncertain', 'succeeded', 'definitive_failed')),
    failure_kind VARCHAR(64),
    code VARCHAR(100),
    attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    last_error TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (order_id, provider)
);

CREATE INDEX idx_delivery_attempts_order ON delivery_attempts (order_id, provider);

CREATE TABLE fulfillments (
    id BIGSERIAL PRIMARY KEY,
    order_id UUID NOT NULL UNIQUE REFERENCES orders (id),
    delivery_attempt_id BIGINT NOT NULL UNIQUE REFERENCES delivery_attempts (id),
    provider CHAR(1) NOT NULL CHECK (provider IN ('A', 'B')),
    request_id VARCHAR(100) NOT NULL,
    code VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE provider_stock (
    id BIGSERIAL PRIMARY KEY,
    provider CHAR(1) NOT NULL CHECK (provider IN ('A', 'B')),
    sku VARCHAR(100) NOT NULL REFERENCES products (sku),
    code VARCHAR(100) NOT NULL UNIQUE,
    issued_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_provider_stock_available
    ON provider_stock (provider, sku, id)
    WHERE issued_at IS NULL;
CREATE INDEX idx_provider_stock_available_by_sku
    ON provider_stock (sku, id)
    WHERE issued_at IS NULL;

CREATE TABLE provider_issues (
    id BIGSERIAL PRIMARY KEY,
    provider CHAR(1) NOT NULL CHECK (provider IN ('A', 'B')),
    request_id VARCHAR(100) NOT NULL,
    order_public_id VARCHAR(80) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    stock_id BIGINT NOT NULL UNIQUE REFERENCES provider_stock (id),
    code VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (provider, request_id)
);

CREATE TABLE stub_provider_settings (
    provider CHAR(1) PRIMARY KEY CHECK (provider IN ('A', 'B')),
    mode VARCHAR(32) NOT NULL DEFAULT 'random'
        CHECK (mode IN ('random', 'success', 'fail_before_issue', 'timeout_after_issue', 'out_of_stock')),
    failure_rate NUMERIC(5, 4) NOT NULL DEFAULT 0 CHECK (failure_rate BETWEEN 0 AND 1),
    timeout_rate NUMERIC(5, 4) NOT NULL DEFAULT 0 CHECK (timeout_rate BETWEEN 0 AND 1),
    timeout_ms INTEGER NOT NULL DEFAULT 1500 CHECK (timeout_ms BETWEEN 1 AND 60000),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO stub_provider_settings (provider, failure_rate, timeout_rate)
VALUES ('A', 0.1000, 0.1000), ('B', 0.0500, 0.0500)
ON CONFLICT (provider) DO NOTHING;
