-- ============================================================
-- CRM PRINT — Index Coverage Audit
-- ============================================================
-- Run on PRODUCTION with real data to get meaningful results.
-- Use psql or pgAdmin. Do NOT run during peak hours.
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. Dashboard Summary (heaviest query — 27 params, FILTER aggregates)
-- ────────────────────────────────────────────────────────────
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT
    COUNT(*) FILTER (WHERE DATE(created_at) = CURRENT_DATE AND type = 'internal') AS today_int,
    COALESCE(SUM(total_cost) FILTER (WHERE created_at >= NOW() - INTERVAL '7 days' AND type = 'internal'), 0) AS week_int_cost,
    COUNT(*) FILTER (WHERE created_at >= DATE_TRUNC('month', NOW()) AND type = 'commercial') AS month_com
FROM orders
WHERE status != 'cancelled'
  AND deleted_at IS NULL
  AND is_backdated = false;

-- ────────────────────────────────────────────────────────────
-- 2. Orders per Day (Analytics chart)
-- ────────────────────────────────────────────────────────────
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT DATE(created_at) as date, count(*) as count
FROM orders
WHERE created_at BETWEEN NOW() - INTERVAL '30 days' AND NOW()
  AND is_backdated = false
GROUP BY DATE(created_at)
ORDER BY date;

-- ────────────────────────────────────────────────────────────
-- 3. Top Services (Analytics — join with order_items)
-- ────────────────────────────────────────────────────────────
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT order_items.service_name, SUM(order_items.quantity) as total_qty
FROM order_items
JOIN orders ON orders.id = order_items.order_id
WHERE order_items.created_at BETWEEN NOW() - INTERVAL '90 days' AND NOW()
  AND orders.is_backdated = false
GROUP BY order_items.service_name
ORDER BY total_qty DESC
LIMIT 10;

-- ────────────────────────────────────────────────────────────
-- 4. Inventory Movements (Forecasting — 30-day consumption)
-- ────────────────────────────────────────────────────────────
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT inventory_item_id, SUM(ABS(quantity)) as total_used
FROM inventory_movements
WHERE type = 'auto_deduct'
  AND created_at >= NOW() - INTERVAL '30 days'
GROUP BY inventory_item_id;

-- ────────────────────────────────────────────────────────────
-- 5. Audit Logs (paginated list with filters)
-- ────────────────────────────────────────────────────────────
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT *
FROM audit_logs
WHERE created_at BETWEEN NOW() - INTERVAL '30 days' AND NOW()
ORDER BY created_at DESC
LIMIT 50 OFFSET 0;

-- ────────────────────────────────────────────────────────────
-- 6. Reconciliation (internal non-backdated orders with date range)
-- ────────────────────────────────────────────────────────────
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT id, order_number, created_at, cost_center, total_cost
FROM orders
WHERE type = 'internal'
  AND is_backdated = false
  AND status != 'cancelled'
  AND deleted_at IS NULL
  AND created_at BETWEEN NOW() - INTERVAL '30 days' AND NOW()
ORDER BY created_at DESC
LIMIT 50;

-- ════════════════════════════════════════════════════════════
-- DIAGNOSTIC: Unused Indexes
-- ════════════════════════════════════════════════════════════
SELECT
    schemaname,
    relname AS table_name,
    indexrelname AS index_name,
    idx_scan AS times_used,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
FROM pg_stat_user_indexes
WHERE idx_scan = 0
  AND schemaname = 'public'
ORDER BY pg_relation_size(indexrelid) DESC;

-- ════════════════════════════════════════════════════════════
-- DIAGNOSTIC: Table Sizes (for growth monitoring)
-- ════════════════════════════════════════════════════════════
SELECT
    relname AS table_name,
    n_live_tup AS row_count,
    pg_size_pretty(pg_total_relation_size(relid)) AS total_size
FROM pg_stat_user_tables
WHERE schemaname = 'public'
ORDER BY pg_total_relation_size(relid) DESC
LIMIT 20;
