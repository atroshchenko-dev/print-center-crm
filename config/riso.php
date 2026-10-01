<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Paper Cost per A3 Sheet (UAH)
    |--------------------------------------------------------------------------
    |
    | Fixed cost of one A3 sheet used for risograph duplication.
    | This value is added to the per-copy printing cost.
    |
    */
    'paper_cost_a3' => (float) env('RISO_PAPER_COST_A3', 0.80),

    /*
    |--------------------------------------------------------------------------
    | Commercial Markup Multiplier
    |--------------------------------------------------------------------------
    |
    | Multiplier applied to cost price for commercial (external) orders.
    | Internal orders are always charged at cost (1.0×).
    | Default: 2.0 (100% markup).
    |
    */
    'commercial_markup' => (float) env('RISO_COMMERCIAL_MARKUP', 2.0),

    /*
    |--------------------------------------------------------------------------
    | Default Paper A3 Name (Inventory Lookup)
    |--------------------------------------------------------------------------
    |
    | Name of the default A3 paper item in inventory.
    | Used for automatic AVCO-based paper cost in riso pricing.
    |
    */
    'default_paper_a3_name' => env('RISO_DEFAULT_PAPER_A3', 'Папір А3 80 г/м²'),
];
