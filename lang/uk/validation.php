<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines (Ukrainian)
    |--------------------------------------------------------------------------
    |
    | Core validation messages for CRM Print.
    | Only the most commonly triggered messages are translated.
    |
    */

    'required' => 'Поле :attribute є обов\'язковим.',
    'email'    => 'Поле :attribute має бути дійсною email-адресою.',
    'min'      => [
        'numeric' => 'Поле :attribute має бути не менше :min.',
        'string'  => 'Поле :attribute має містити не менше :min символів.',
    ],
    'max' => [
        'numeric' => 'Поле :attribute має бути не більше :max.',
        'string'  => 'Поле :attribute має містити не більше :max символів.',
    ],
    'numeric'   => 'Поле :attribute має бути числом.',
    'integer'   => 'Поле :attribute має бути цілим числом.',
    'string'    => 'Поле :attribute має бути рядком.',
    'confirmed' => 'Підтвердження поля :attribute не збігається.',
    'unique'    => 'Таке значення поля :attribute вже існує.',
    'exists'    => 'Обране значення поля :attribute недійсне.',
    'in'        => 'Обране значення поля :attribute недійсне.',
    'gte'       => [
        'numeric' => 'Поле :attribute має бути не менше :value.',
    ],
    'gt' => [
        'numeric' => 'Поле :attribute має бути більше :value.',
    ],
    'decimal' => 'Поле :attribute має мати :decimal знаків після коми.',
    'boolean' => 'Поле :attribute має бути true або false.',
    'array'   => 'Поле :attribute має бути масивом.',
    'date'    => 'Поле :attribute має бути дійсною датою.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Every key any rules() validates belongs here, and `AttributesAreNamedTest`
    | fails if one is missing. That is not tidiness — it is the second half of
    | the owner's decision of 2026-08-02 (CLOSEOUT §1.9).
    |
    | That decision made AppLayout render every refusal in a banner, so nothing
    | is invisible any more. But the banner shows Laravel's sentence and nothing
    | else, and Laravel fills :attribute from this list; a field missing from it
    | falls back to its own column name with the underscores turned to spaces.
    | Measured in round 24: 132 of 145 validated fields had no entry, so the
    | operator read «Поле min quantity є обов'язковим.» — a Ukrainian sentence
    | ending in an English column.
    |
    | The nested keys are spelled out in full because Laravel looks up the exact
    | key: `items.*.quantity` is a different lookup from `quantity`.
    |
    */

    'attributes' => [

        // ─── Вхід і користувачі ───────────────────────────
        'login'         => 'логін',
        'email'         => 'email',
        'password'      => 'пароль',
        'name'          => 'назва',
        'full_name'     => 'ПІБ',
        'role'          => 'роль',
        'permissions'   => 'дозволи',
        'permissions.*' => 'дозвіл',
        'is_active'     => 'активність',
        'token'         => 'токен',

        // ─── Замовлення ───────────────────────────────────
        'type'                => 'тип',
        'status'              => 'статус',
        'authorized_person'   => 'уповноважена особа',
        'cost_center'         => 'центр витрат',
        'payment_method'      => 'метод оплати',
        'initiator'           => 'ініціатор',
        'comment'             => 'коментар',
        'reason'              => 'причина',
        'edit_reason'         => 'причина редагування',
        'quantity'            => 'кількість',
        'amount'              => 'сума',
        'version'             => 'версія запису',
        'is_at_cost'          => 'по собівартості',
        'limit_exceeded'      => 'ліміт перевищено',
        'request_received'    => 'заявка отримана',
        'is_technical_defect' => 'технічний брак',
        'order_date'          => 'дата замовлення',
        'order_ids'           => 'замовлення',
        'order_ids.*'         => 'замовлення',
        'ids'                 => 'записи',
        'ids.*'               => 'запис',
        'total_cost'          => 'собівартість',

        // ─── Позиції замовлення ───────────────────────────
        'items'                         => 'позиції',
        'items.*.service_id'            => 'послуга',
        'items.*.quantity'              => 'кількість',
        'items.*.material_description'  => 'опис матеріалу',
        'customer_paper'                => 'папір замовника',
        'items.*.customer_paper'        => 'папір замовника',
        'items.*.existing_snapshot'     => 'збережений розрахунок',
        'items.*.selected_option_ids'   => 'обрані параметри',
        'items.*.selected_option_ids.*' => 'обраний параметр',
        'selected_option_ids'           => 'обрані параметри',
        'selected_option_ids.*'         => 'обраний параметр',

        // ─── Різо ─────────────────────────────────────────
        'items.*.riso_format'    => 'формат Різо',
        'items.*.riso_originals' => 'кількість оригіналів',
        'items.*.riso_paper_id'  => 'папір Різо',
        'items.*.riso_sides'     => 'сторонність Різо',
        'tiers'                  => 'тарифи',
        'tiers.*.id'             => 'тариф',
        'tiers.*.min_qty'        => 'від (кількість)',
        'tiers.*.max_qty'        => 'до (кількість)',
        'tiers.*.cost_per_copy'  => 'ціна за копію',
        'min_qty'                => 'від (кількість)',
        'max_qty'                => 'до (кількість)',
        'cost_per_copy'          => 'ціна за копію',

        // ─── Брошури ──────────────────────────────────────
        'items.*.brochure_format'                 => 'формат брошури',
        'items.*.brochure_cover_paper_id'         => 'папір обкладинки',
        'items.*.brochure_cover_mode'             => 'друк обкладинки',
        'items.*.brochure_block_paper_id'         => 'папір блоку',
        'items.*.brochure_block_entries'          => 'блоки',
        'items.*.brochure_block_entries.*.sheets' => 'аркушів у блоці',
        'items.*.brochure_block_entries.*.mode'   => 'друк блоку',

        // ─── Дипломи й додатки ────────────────────────────
        'items.*.diploma_params'                                          => 'параметри дипломів',
        'items.*.diploma_params.diplomas'                                 => 'дипломи',
        'items.*.diploma_params.diplomas.qty'                             => 'кількість дипломів',
        'items.*.diploma_params.supplements'                              => 'додатки',
        'items.*.diploma_params.supplements.*.label'                      => 'назва додатка',
        'items.*.diploma_params.supplements.*.type'                       => 'тип додатка',
        'items.*.diploma_params.supplements.*.qty'                        => 'кількість додатків',
        'items.*.diploma_params.supplements.*.blocks'                     => 'блоки додатка',
        'items.*.diploma_params.supplements.*.blocks.*.sheets'            => 'аркушів у блоці додатка',
        'items.*.diploma_params.supplements.*.blocks.*.mode'              => 'друк блоку додатка',
        'items.*.diploma_params.academic_records'                         => 'академдовідки',
        'items.*.diploma_params.academic_records.qty'                     => 'кількість академдовідок',
        'items.*.diploma_params.copies'                                   => 'копії',
        'items.*.diploma_params.copies.diploma_copies'                    => 'копії дипломів',
        'items.*.diploma_params.copies.diploma_copies.qty'                => 'кількість копій дипломів',
        'items.*.diploma_params.copies.diploma_copies.mode'               => 'друк копій дипломів',
        'items.*.diploma_params.copies.supplement_copies'                 => 'копії додатків',
        'items.*.diploma_params.copies.supplement_copies.qty'             => 'кількість копій додатків',
        'items.*.diploma_params.copies.supplement_copies.blocks'          => 'блоки копій додатків',
        'items.*.diploma_params.copies.supplement_copies.blocks.*.sheets' => 'аркушів у блоці копії',
        'items.*.diploma_params.copies.supplement_copies.blocks.*.mode'   => 'друк блоку копії',

        // ─── Послуги й конструктор ────────────────────────
        'service_id'                  => 'послуга',
        'service_category_id'         => 'категорія послуги',
        'signatory_group_id'          => 'група підписантів',
        'department_id'               => 'центр витрат',
        'category_ids'                => 'категорії',
        'category_ids.*'              => 'категорія',
        'available_for'               => 'доступно для',
        'available_for.*'             => 'доступно для',
        'base_price_cost'             => 'базова собівартість',
        'base_price_commercial'       => 'базова комерційна ціна',
        'price_markup'                => 'націнка',
        'options'                     => 'параметри',
        'options.*.id'                => 'параметр',
        'options.*.inventory_item_id' => 'позиція складу',
        'options.*.inventory_qty'     => 'витрата зі складу',
        'ui_style'                    => 'вигляд',
        'ui_type'                     => 'тип вибору',
        'is_required'                 => 'обов\'язковість',
        'depends_on'                  => 'залежність',
        'depends_on.group_id'         => 'група залежності',
        'depends_on.option_ids'       => 'параметри залежності',
        'sort_order'                  => 'порядок',
        'position'                    => 'позиція',
        'order'                       => 'порядок',
        'order.*.id'                  => 'запис',
        'order.*.sort_order'          => 'порядок',

        // ─── Склад ────────────────────────────────────────
        'inventory_category_id' => 'категорія складу',
        'inventory_item_id'     => 'позиція складу',
        'inventory_qty'         => 'витрата зі складу',
        'subcategory'           => 'підкатегорія',
        'unit'                  => 'одиниця виміру',
        'current_quantity'      => 'кількість',
        'empty_quantity'        => 'порожніх',
        'min_quantity'          => 'мінімальний залишок',
        'quantity_change'       => 'зміна кількості',
        'avg_cost'              => 'середня собівартість',
        'refill_cost'           => 'вартість заправки',
        'units_per_pack'        => 'одиниць в упаковці',
        'packs_quantity'        => 'кількість упаковок',
        'price_per_pack'        => 'ціна за упаковку',
        'auto_update_markup'    => 'оновити ціни параметрів',
        'source_item_id'        => 'позиція-джерело',
        'target_item_id'        => 'позиція-призначення',
        'ratio'                 => 'коефіцієнт',
        'notes'                 => 'примітка',

        // ─── Апарати й лічильники ─────────────────────────
        'equipment_id'             => 'апарат',
        'serial_number'            => 'серійний номер',
        'has_counter'              => 'наявність лічильника',
        'counter_type'             => 'тип лічильника',
        'initial_counter'          => 'початковий показник',
        'counter_value'            => 'показник лічильника',
        'readings'                 => 'показники лічильників',
        'readings.*.equipment_id'  => 'апарат',
        'readings.*.counter_value' => 'показник лічильника',

        // ─── Зміна й каса ─────────────────────────────────
        'cash_actual'        => 'фактична готівка',
        'discrepancy_reason' => 'причина розбіжності',

        // ─── Матеріали й ціни ─────────────────────────────
        'click_cost'           => 'вартість кліка',
        'clicks_per_unit'      => 'кліків на одиницю',
        'pending_click_cost'   => 'відкладена вартість кліка',
        'pending_activated_at' => 'дата активації ціни',
        'daily_limit'          => 'денний ліміт',

        // ─── Налаштування ─────────────────────────────────
        'key'   => 'ключ налаштування',
        'value' => 'значення',
    ],

];
