<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every event type the audit journal can hold.
 *
 * The list used to live in `Reports/Audit.vue` as a literal array of twelve
 * strings, and nothing tied it to what the code writes. Thirteen event types
 * were missing from it — including `approval_email_failed`, which round 13 added
 * for the express purpose of making an undelivered signature request visible —
 * so the only way to see one was to page through the journal until it appeared.
 * The thirteenth entry in that array, `counter_adjusted`, is written by no line
 * in the project: `CounterAdjustmentController` records `counter_adjustment`.
 * Picking "Корекція лічильника" therefore returned an empty page, always, while
 * the real events sat one slug away with no way to filter for them.
 *
 * Writers still pass plain strings — `AuditLog::record()` takes a string, and
 * routing two dozen call sites through this enum is a change of a different
 * size. What this holds is the catalogue, and `AuditEventCatalogueTest` fails if
 * a call site ever writes a type that is not in it.
 */
enum AuditEventType: string
{
    // Orders
    case OrderCancelled = 'order_cancelled';
    case OrderDefect = 'order_defect';
    case OrderEdited = 'order_edited';
    case OrderDeleted = 'order_deleted';
    case LimitExceeded = 'limit_exceeded';
    case CategoryNotAllowed = 'category_not_allowed';

    // Email approvals
    case OrderApprovedEmail = 'order_approved_email';
    case OrderRejectedEmail = 'order_rejected_email';
    case ApprovalEmailFailed = 'approval_email_failed';

    // Cash and shifts
    case CashWithdrawal = 'cash_withdrawal';
    case CashDiscrepancy = 'cash_discrepancy';
    case ShiftOpened = 'shift_opened';
    case ShiftClosed = 'shift_closed';
    case ShiftAutoClosed = 'shift_auto_closed';
    case SettlementCompleted = 'settlement_completed';
    case CounterAdjustment = 'counter_adjustment';

    // Stock and prices
    case InventoryDeficit = 'inventory_deficit';
    case InventoryReceiptReversed = 'inventory_receipt_reversed';
    case PaperAutoConversion = 'paper_auto_conversion';
    case PriceActivated = 'price_activated';
    case PriceScheduled = 'price_scheduled';

    // Users and privacy
    case PermissionsChanged = 'permissions_changed';
    case RoleChanged = 'role_changed';
    case UserDeactivated = 'user_deactivated';
    case UserRestored = 'user_restored';
    case PrivacyPrune = 'privacy_prune';

    public function label(): string
    {
        return match ($this) {
            self::OrderCancelled           => 'Скасування замовлення',
            self::OrderDefect              => 'Брак',
            self::OrderEdited              => 'Редагування замовлення',
            self::OrderDeleted             => 'Видалення замовлення',
            self::LimitExceeded            => 'Перевищення ліміту',
            self::CategoryNotAllowed       => 'Категорія поза дозволеними',
            self::OrderApprovedEmail       => 'Погоджено листом',
            self::OrderRejectedEmail       => 'Відхилено листом',
            self::ApprovalEmailFailed      => 'Лист погодження не доставлено',
            self::CashWithdrawal           => 'Вилучення готівки',
            self::CashDiscrepancy          => 'Розбіжність каси',
            self::ShiftOpened              => 'Відкриття зміни',
            self::ShiftClosed              => 'Закриття зміни',
            self::ShiftAutoClosed          => 'Автозакриття зміни',
            self::SettlementCompleted      => 'Врегулювання зміни',
            self::CounterAdjustment        => 'Корекція лічильника',
            self::InventoryDeficit         => 'Дефіцит складу',
            self::InventoryReceiptReversed => 'Сторно оприбуткування',
            self::PaperAutoConversion      => 'Автоконвертація паперу',
            self::PriceActivated           => 'Активація ціни',
            self::PriceScheduled           => 'Заплановано ціну',
            self::PermissionsChanged       => 'Зміна доступу',
            self::RoleChanged              => 'Зміна ролі',
            self::UserDeactivated          => 'Деактивація користувача',
            self::UserRestored             => 'Відновлення користувача',
            self::PrivacyPrune             => 'Очищення персональних даних',
        };
    }

    /**
     * The filter list and the badge captions the audit screen draws, in one
     * shape, so the page never has to keep its own copy again.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
