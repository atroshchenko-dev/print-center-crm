<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Personal Data Retention
|--------------------------------------------------------------------------
| Audit finding M-7: the system had no retention policy at all, so the
| personal data of approval signatories — name, email, and the IP they
| answered from — accumulated for as long as the database lived.
|
| The record of *who approved what* is a financial control and has to
| survive. What does not have to survive is the ability to contact or
| locate that person years later. So approvals are anonymised in place
| rather than deleted: the row, its status and its timestamps stay,
| the identifying fields go.
|
| Run by `privacy:prune`, scheduled nightly. See docs/PII.md.
*/

return [

    /*
     * How long the signatory's name, email and IP are kept, counted from the
     * moment the approval was answered (or, for ones never answered, from the
     * moment the link expired). A year covers the audit cycle the печатний
     * відділ reconciles against.
     */
    'approval_retention_days' => (int) env('PRIVACY_APPROVAL_RETENTION_DAYS', 365),

    /*
     * How long the customer's name on a business-card order is kept, counted
     * from when the order was entered — that is when the data was collected.
     *
     * Owner's decision, 2026-07-31, closing a question open since round 2: the
     * same treatment as approvals. Only the «Візитівки» category is touched;
     * `material_description` on other items holds order content («Плакати»,
     * «Дипломи 2026, 3 курс») and no personal data, and blanking it would
     * destroy history for no privacy gain.
     *
     * A separate key from the approvals one on purpose: they are different
     * categories of data and may want different windows later. Reusing a key
     * named after approvals for order items would be a name that lies.
     */
    'order_item_retention_days' => (int) env('PRIVACY_ORDER_ITEM_RETENTION_DAYS', 365),

    /*
     * What anonymised fields are set to. Kept human-readable on purpose: an
     * operator looking at an old order should understand that the data was
     * removed on schedule, not lost.
     */
    'placeholder' => '[видалено за політикою зберігання]',

];
