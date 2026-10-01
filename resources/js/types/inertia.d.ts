/**
 * CRM Print — Inertia Page Props Type Definitions
 *
 * Provides TypeScript autocomplete for props passed from Laravel controllers
 * to Vue pages via Inertia.
 *
 * Usage in .vue files:
 *   const props = defineProps<DashboardProps>();
 *
 * ─── Read this before trusting it ───────────────────────────────────────
 *
 * Nothing enforces these types. Every page uses the runtime object form —
 * `defineProps({ order: Object })` — so not one of these interfaces is
 * checked against anything, by the compiler or by CI. They are documentation
 * that happens to be written in TypeScript.
 *
 * That is why the file rotted: audit finding L-7 found it three months behind
 * the schema, with diplomas, business cards, toners and digital approvals
 * missing entirely and `ShiftOpenProps` naming props the controller never sent
 * (`cashStart`, `needsSettlement` — the payload is snake_case).
 *
 * The model interfaces below were regenerated from the live schema on
 * 2026-07-27 and match it. The page-props interfaces cover roughly half the
 * pages and are the part most likely to drift again — verify against the
 * controller before relying on one. Making them load-bearing means converting
 * pages to `defineProps<T>()`, which is a separate piece of work.
 */

// ─── Enums ──────────────────────────────────────────────

export type OrderStatus =
    | 'new'
    | 'in_progress'
    | 'ready'
    | 'paid_issued'
    | 'completed_issued'
    | 'cancelled';

export type OrderType = 'internal' | 'commercial';

export type PaymentMethod = 'cash' | 'card';

export type ShiftStatus = 'open' | 'closed' | 'auto_closed';

export type LedgerTransactionType =
    | 'shift_start'
    | 'payment_cash'
    | 'payment_card'
    | 'withdrawal'
    | 'reversal'
    | 'shift_close_actual'
    | 'shift_close_expected';

export type UserRole = 'admin' | 'manager' | 'executor';

export type Permission =
    | 'orders'
    | 'ledger'
    | 'reports'
    | 'services'
    | 'equipment'
    | 'inventory'
    | 'university'
    | 'users';

export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'superseded';

export type EquipmentType = 'bw' | 'color' | 'riso';

// ─── Core Models ────────────────────────────────────────

export interface User {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    is_active: boolean;
    permissions: Permission[];
}

export interface Shift {
    id: number;
    date: string;
    status: ShiftStatus;
    opened_by: number;
    closed_by: number | null;
    cash_start: number;
    cash_calculated: number | null;
    cash_actual: number | null;
    cash_discrepancy_reason: string | null;
    auto_closed: boolean;
    settlement_required: boolean;
    settlement_done: boolean;
    opened_at: string;
    closed_at: string | null;
    opener?: User;
}

export interface Order {
    id: number;
    order_number: string;
    order_prefix: string;
    order_year: number;
    order_month: number;
    order_sequence: number;
    type: OrderType;
    status: OrderStatus;
    payment_method: PaymentMethod | null;
    /** Signatory chosen from university_refs, stored as free text on the order */
    authorized_person: string | null;
    cost_center: string | null;
    /** Who asked for the job, when it differs from the signatory */
    initiator: string | null;
    limit_exceeded: boolean;
    /** Commercial order billed at cost instead of the commercial price */
    is_at_cost: boolean;
    /** Entered after the fact by an admin, outside the normal shift flow */
    is_backdated: boolean;
    total_cost: number;
    total_commercial: number;
    cancellation_reason: string | null;
    is_technical_defect: boolean;
    is_reconciled: boolean;
    reconciled_at: string | null;
    reconciled_by: number | null;
    /** Paper request form physically handed in (internal orders only) */
    request_received: boolean;
    request_received_at: string | null;
    request_received_by: number | null;
    version: number;
    shift_id: number | null;
    user_id: number;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    items?: OrderItem[];
    user?: User;
    approvals?: OrderApproval[];
}

export interface OrderItem {
    id: number;
    order_id: number;
    service_id: number | null;
    service_name: string;
    /**
     * Frozen copy of the service and its chosen options at the moment of sale.
     * Shape varies by service type — constructor_snapshot for print and cards,
     * inventory_deductions for brochures, diploma_* for diplomas.
     */
    service_snapshot: ServiceSnapshot;
    quantity: number;
    unit_price_commercial: number;
    unit_price_cost: number;
    total_price_commercial: number;
    total_price_cost: number;
    bw_clicks: number;
    color_clicks: number;
    riso_clicks: number;
    /** Free text: name on business cards, paper brought by the customer, etc. */
    material_description: string | null;
    created_at: string;
    updated_at: string;
}

/**
 * The JSONB snapshot on an order item. Only the keys the frontend reads are
 * listed; the backend writes more per service type.
 */
export interface ServiceSnapshot {
    customer_paper?: boolean;
    constructor_snapshot?: ConstructorSnapshotEntry[];
    inventory_deductions?: Array<{ inventory_item_id: number; qty: number }>;
    [key: string]: unknown;
}

export interface ConstructorSnapshotEntry {
    option_id: number;
    group_name: string;
    option_name: string;
    price_markup: number;
    cost_markup: number;
    counter_type: EquipmentType | null;
    clicks: number | null;
    inventory_item_id: number | null;
    inventory_qty: number;
}

/**
 * One-time email approval link for an internal order.
 * Signatories have no CRM account — they answer through a signed URL.
 */
export interface OrderApproval {
    id: number;
    order_id: number;
    token: string;
    signatory_email: string;
    signatory_name: string;
    status: ApprovalStatus;
    rejection_reason: string | null;
    responded_at: string | null;
    responded_ip: string | null;
    expires_at: string;
    /** Set once the retention policy has stripped the signatory's data */
    anonymized_at: string | null;
}

export interface LedgerTransaction {
    id: number;
    shift_id: number;
    order_id: number | null;
    /** The transaction this one reverses; at most one reversal per transaction */
    reversed_transaction_id: number | null;
    type: LedgerTransactionType;
    payment_method: PaymentMethod | null;
    amount: number;
    balance_after: number;
    comment: string | null;
    user_id: number;
    created_at: string;
    user?: User;
}

export interface Department {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
}

export interface ServiceCategory {
    id: number;
    name: string;
    sort_order: number;
    is_active: boolean;
}

export interface Service {
    id: number;
    name: string;
    service_category_id: number;
    is_static: boolean;
    static_price: number | null;
    is_active: boolean;
    sort_order: number;
    visibility: 'all' | 'internal' | 'commercial';
    category?: ServiceCategory;
    groups?: ServiceParameterGroup[];
}

export interface ServiceParameterGroup {
    id: number;
    service_id: number;
    name: string;
    ui_style: 'radio' | 'select' | 'cascade';
    sort_order: number;
    options?: ServiceParameterOption[];
}

export interface ServiceParameterOption {
    id: number;
    group_id: number;
    name: string;
    short_name: string | null;
    cost_markup: number;
    commercial_markup: number | null;
    is_default: boolean;
    parent_option_id: number | null;
    inventory_item_id: number | null;
    inventory_qty: number;
    sort_order: number;
}

export interface InventoryItem {
    id: number;
    name: string;
    unit: string;
    subcategory: string | null;
    current_quantity: number;
    avg_cost: number;
    min_quantity: number;
    /** Toners only: cartridges taken out of a machine, waiting to be refilled */
    empty_quantity: number;
    /** Toners only: what a refill costs, used to reprice on return */
    refill_cost: number;
    /** Cutting source, e.g. A4 is cut from A3 */
    convertible_from_id: number | null;
    /** Units produced from one unit of the source (2 for A3 → A4) */
    conversion_ratio: number;
    inventory_category_id: number | null;
    is_active: boolean;
    sort_order: number;
    category?: InventoryCategory;
    convertibleFrom?: InventoryItem;
}

export interface InventoryCategory {
    id: number;
    name: string;
    sort_order: number;
    is_active: boolean;
}

export interface Equipment {
    id: number;
    name: string;
    type: EquipmentType;
    is_active: boolean;
}

export interface RisoPriceTier {
    id: number;
    min_qty: number;
    max_qty: number | null;
    cost_per_copy: number;
}

export interface UniversityRef {
    id: number;
    name: string;
    position: string | null;
    is_active: boolean;
    group?: SignatoryGroup;
}

export interface SignatoryGroup {
    id: number;
    name: string;
    categories?: ServiceCategory[];
}

export interface AuditLog {
    id: number;
    event_type: string;
    user_id: number;
    description: string;
    entity_id: number | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
    user?: User;
}

// ─── Pagination ─────────────────────────────────────────

export interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

// ─── Page Props ─────────────────────────────────────────

export interface DashboardProps {
    shift: Shift;
    chartData: {
        labels: string[];
        internalCounts: number[];
        commercialCounts: number[];
        internalRevenue: number[];
        commercialRevenue: number[];
        todayOrders: number;
        weekRevenue: number;
        monthOrders: number;
        monthRevenue: number;
        lastMonthOrders: number;
        lastMonthRevenue: number;
    } | null;
}

export interface OrdersIndexProps {
    orders: PaginatedResponse<Order>;
    filters: Record<string, string | null>;
}

export interface OrderCreateProps {
    services: Service[];
    categories: ServiceCategory[];
    departments: Department[];
    signatories: UniversityRef[];
    riso_tiers: RisoPriceTier[];
    riso_papers: Pick<InventoryItem, 'id' | 'name' | 'avg_cost' | 'unit'>[];
    riso_paper_cost: number;
}

export interface OrderShowProps {
    order: Order & { items: OrderItem[]; user: User };
}

export interface LedgerHistoryProps {
    transactions: LedgerTransaction[];
    balance: number;
    shift: Shift;
}

export interface ShiftOpenProps {
    equipment: Array<Equipment & { last_reading: number }>;
    cash_start: number;
    last_shift: {
        date: string;
        cash_actual: number | null;
        auto_closed: boolean;
    } | null;
    /** Present only when the previous shift still needs settling */
    previous_shift: {
        date: string;
        cash_calculated: number;
        ledger_history?: Array<Pick<LedgerTransaction, 'id' | 'amount' | 'comment'>>;
    } | null;
}

export interface ReportInternalProps {
    data: Record<string, {
        orders: Order[];
        total_cost: number;
        bw_clicks: number;
        color_clicks: number;
    }>;
    filters: { from: string | null; to: string | null };
    totals: { orders: number; cost: number };
}

export interface ReportCommercialProps {
    orders: Order[];
    filters: { from: string | null; to: string | null };
    totals: {
        count: number;
        commercial: number;
        cash_count: number;
        card_count: number;
    };
}

export interface ReportAuditProps {
    logs: PaginatedResponse<AuditLog>;
    filters: Record<string, string | null>;
    users: Pick<User, 'id' | 'name'>[];
}

export interface InventoryIndexProps {
    items: InventoryItem[];
    parameterOptions: {
        id: number;
        name: string;
        group_name: string;
        service_name: string;
        service_category_name: string;
        service_category_sort: number;
        inventory_item_id: number | null;
        inventory_qty: number;
    }[];
    categories: InventoryCategory[];
}

// ─── Shared Inertia Props ───────────────────────────────

export interface SharedProps {
    auth: {
        user: User;
    };
    flash: {
        success?: string;
        error?: string;
    };
    currentShift?: Shift;
}
