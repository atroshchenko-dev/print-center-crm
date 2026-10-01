<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reorder service categories for the commercial price list.
     * New order: ЧБ друк → Колір → Ламінування → Сканування → Тверда → М'яка
     */
    public function up(): void
    {
        $order = [
            'Чорно-білий друк'  => 10,
            'Кольоровий друк'   => 20,
            'Ламінування'       => 30,
            'Сканування'        => 40,
            'Палітурка тверда'  => 50,
            "Палітурка м'яка"   => 60,
            'Брошури'           => 70,
            'Дипломи/Додатки'   => 80,
            'Тиражування'       => 90,
            'Візитівки'         => 100,
            'Розрізання паперу' => 110,
        ];

        foreach ($order as $name => $sortOrder) {
            DB::table('service_categories')
                ->where('name', $name)
                ->update(['sort_order' => $sortOrder]);
        }
    }

    public function down(): void
    {
        $order = [
            'Чорно-білий друк'  => 10,
            'Кольоровий друк'   => 20,
            'Сканування'        => 30,
            'Палітурка тверда'  => 40,
            "Палітурка м'яка"   => 50,
            'Брошури'           => 60,
            'Ламінування'       => 70,
            'Дипломи/Додатки'   => 80,
            'Тиражування'       => 90,
            'Візитівки'         => 100,
            'Розрізання паперу' => 110,
        ];

        foreach ($order as $name => $sortOrder) {
            DB::table('service_categories')
                ->where('name', $name)
                ->update(['sort_order' => $sortOrder]);
        }
    }
};
