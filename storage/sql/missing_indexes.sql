-- ============================================================
-- CRM PRINT — Recommended Missing Indexes
-- ============================================================
-- Apply ONLY after running index_audit.sql and confirming
-- that sequential scans dominate in the EXPLAIN output.
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. Dashboard / Reports / Analytics composite
--    Covers: WHERE status != ? AND is_backdated = false AND created_at BETWEEN ...
--    Most frequent query pattern across Dashboard, Reports, Analytics.
-- ────────────────────────────────────────────────────────────
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_orders_operational_date
    ON orders (created_at DESC)
    WHERE is_backdated = false AND deleted_at IS NULL;

-- ────────────────────────────────────────────────────────────
-- 2. Type-filtered operational queries (INT vs COM split)
--    Covers: Dashboard summary, Report internal/commercial split.
-- ────────────────────────────────────────────────────────────
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_orders_type_operational
    ON orders (type, created_at DESC)
    WHERE is_backdated = false AND deleted_at IS NULL AND status != 'cancelled';

-- ────────────────────────────────────────────────────────────
-- 3. Inventory Movements — forecasting query
--    Covers: WHERE type = 'auto_deduct' AND created_at >= ?
--    GROUP BY inventory_item_id
-- ────────────────────────────────────────────────────────────
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inv_movements_deduct_date
    ON inventory_movements (inventory_item_id, created_at DESC)
    WHERE type = 'auto_deduct';

-- ────────────────────────────────────────────────────────────
-- 4. Audit Logs — paginated list (most recent first)
--    Covers: ORDER BY created_at DESC LIMIT 50
-- ────────────────────────────────────────────────────────────
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_logs_date
    ON audit_logs (created_at DESC);

-- ────────────────────────────────────────────────────────────
-- 5. Order Items FK index (verify existence)
--    If missing, JOINs with orders table will seq-scan.
-- ────────────────────────────────────────────────────────────
-- Check first:
--   SELECT indexname FROM pg_indexes WHERE tablename = 'order_items' AND indexdef LIKE '%order_id%';
--
-- If empty, create:
-- CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_order_items_order_id
--     ON order_items (order_id);

-- ────────────────────────────────────────────────────────────
-- 6. Reconciliation — internal orders by date
--    Covers: WHERE type = 'internal' AND is_backdated = false
-- ────────────────────────────────────────────────────────────
-- Already covered by idx_orders_type_operational (index #2)

-- ════════════════════════════════════════════════════════════
-- VERIFICATION: Confirm indexes were created
-- ════════════════════════════════════════════════════════════
SELECT indexname, indexdef
FROM pg_indexes
WHERE tablename IN ('orders', 'inventory_movements', 'audit_logs', 'order_items')
  AND schemaname = 'public'
ORDER BY tablename, indexname;
