<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use Illuminate\Database\Seeder;

class UniversityRefSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Resolve category IDs ────────────────────────
        $allCategories = ServiceCategory::whereNull('deleted_at')->pluck('id', 'name');

        // ─── Group 1: Full access (all categories, no limit) ─
        $groupFull = SignatoryGroup::updateOrCreate(
            ['name' => 'Повний доступ (1–10)'],
            ['daily_limit' => null, 'sort_order' => 10, 'is_active' => true]
        );
        $groupFull->categories()->sync($allCategories->values()->toArray());

        // ─── Group 2: Limited categories (5–10) ──────────
        $limitedNames = [
            'Палітурка тверда',
            "Палітурка м'яка",
            'Ламінування',
            'Чорно-білий друк',
            'Розрізання паперу',
        ];
        $groupLimited = SignatoryGroup::updateOrCreate(
            ['name' => 'Обмежений доступ (5–10)'],
            ['daily_limit' => null, 'sort_order' => 20, 'is_active' => true]
        );
        $groupLimited->categories()->sync(
            $allCategories->only($limitedNames)->values()->toArray()
        );

        // ─── Group 3: B&W only with daily limit ─────────
        $groupBwOnly = SignatoryGroup::updateOrCreate(
            ['name' => 'Тільки Ч/Б (ліміт 20/день)'],
            ['daily_limit' => 20, 'sort_order' => 30, 'is_active' => true]
        );
        $bwId = $allCategories->get('Чорно-білий друк');
        if ($bwId) {
            $groupBwOnly->categories()->sync([$bwId]);
        }

        // ─── Assign signatories to groups ────────────────

        $group1_10 = [
            'Алчук В.Г.', 'Архіпович І.В.', 'Гучук Ю.М.', 'Демичук Г.І.',
            'Карприна О.М.', 'Кучвак А.М.', 'Лапвак М.С.', 'Лапвак С.М.',
            'Літчук Н.М.', 'Лотенко А.Г.', 'Леський П.С.', 'Мічченко С.М.',
            'Момник Н.М.', 'Накченко Н.В.', 'Паращець Л.І.', 'Старчович А.М.',
            'Степець Н.В.', 'Стуник М.Ю.', 'Сумбнова Л.П.',
            'Титвак Н.В.', 'Ткський Д.І.', 'Фініник Т.В.',
        ];

        $group5_10 = [
            'Бердник С.О.', 'Горський О.М.', 'Іщчук Л.В.', 'Королченко В.В.',
            'Мелець Л.Ф.', 'Нікенко О.В.', 'Палачук О.Є.', 'Дьник І.В.',
            'Правдник О.М.', 'Румський І.І.', 'Слечук Л.В.', 'Сушвак А.М.',
            'Трочук В.В.', 'Балдець Д.О.', 'Прохрина М.Е.', 'Дронова А.О.',
            'Прилченко С.М.', 'Новаський І.М.', 'Фечук М.В.', 'Новаський О.М.',
            'Гаркченко В.В.', 'Сиренко Т.Ф.', 'Гольська А.Ю.', 'Головако В.В.',
        ];

        $group11 = ['Панович Т.Г.', 'Прувак О.І.'];

        foreach ($group1_10 as $name) {
            UniversityRef::updateOrCreate(
                ['full_name' => $name],
                ['is_active' => true, 'signatory_group_id' => $groupFull->id]
            );
        }

        foreach ($group5_10 as $name) {
            UniversityRef::updateOrCreate(
                ['full_name' => $name],
                ['is_active' => true, 'signatory_group_id' => $groupLimited->id]
            );
        }

        foreach ($group11 as $name) {
            UniversityRef::updateOrCreate(
                ['full_name' => $name],
                ['is_active' => true, 'signatory_group_id' => $groupBwOnly->id]
            );
        }

        $this->command->info('✅ Signatory groups seeded: 3 groups, ' . count($group1_10) + count($group5_10) + count($group11) . ' signatories.');
    }
}
