<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RisoPriceTier;
use App\Models\ServiceCategory;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PriceListController
 *
 * Public-facing commercial price list page.
 * Builds pivoted price tables: formats (А4/А3) as columns, paper+fill as rows.
 */
class PriceListController extends Controller
{
    /**
     * Categories rendered as a "band → price" table instead of a flat list.
     * Presentation only — every number still comes out of the database.
     */
    private const TIER_CATEGORIES = [
        "Палітурка м'яка",
        'Палітурка тверда',
    ];

    public function index(): Response
    {
        $categories = ServiceCategory::where('is_active', true)
            ->whereJsonContains('available_for', 'commercial')
            ->with([
                'services' => fn ($q) => $q
                    ->where('is_active', true)
                    ->with([
                        'parameterGroups' => fn ($g) => $g
                            ->orderBy('sort_order')
                            ->with([
                                'options' => fn ($o) => $o
                                    ->where('is_active', true)
                                    ->orderBy('sort_order'),
                            ]),
                    ]),
            ])
            ->orderBy('sort_order')
            ->get();

        $priceData = [];
        foreach ($categories as $cat) {
            $built = $this->buildCategory($cat);
            if ($built !== null) {
                $priceData[] = $built;
            }
        }

        $risoTiers = RisoPriceTier::orderBy('min_qty')->get();

        return Inertia::render('PriceList/Index', [
            'categories' => $priceData,
            'riso_tiers' => $risoTiers,
            'updated_at' => now()->format('d.m.Y'),
        ]);
    }

    private function buildCategory(ServiceCategory $category): ?array
    {
        $result = [
            'id'   => $category->id,
            'name' => $category->name,
            'type' => 'simple',
            'formats' => [],
            'paper_groups' => [],
            'simple_rows' => [],
        ];

        foreach ($category->services as $service) {
            $groups = $service->parameterGroups;

            if ($groups->isEmpty()) {
                // Static service
                $price = (float) $service->base_price_commercial;
                if ($price > 0) {
                    $result['simple_rows'][] = [
                        'label' => $service->name,
                        'price' => $price,
                    ];
                }
                continue;
            }

            // Identify group roles
            $rootGroup = null;
            $fillGroup = null;
            $middleGroups = [];

            foreach ($groups as $group) {
                if (!$group->is_required && $group->name === 'Заповненість') {
                    $fillGroup = $group;
                    continue;
                }

                $isRoot = true;
                foreach ($group->options as $opt) {
                    if (!empty($opt->depends_on)) {
                        $isRoot = false;
                        break;
                    }
                }

                if ($isRoot && $group->options->isNotEmpty()) {
                    $rootGroup = $group;
                } else {
                    $middleGroups[] = $group;
                }
            }

            if (!$rootGroup) {
                continue;
            }

            // Only use pivot (formats as columns) where the root group is
            // "Формат" (А4/А3) — print and lamination. Binding has no format
            // axis and flattens into rows.
            $isPivot = $rootGroup->name === 'Формат';

            if ($isPivot) {
                // Collect format names as column headers
                $formatNames = [];
                foreach ($rootGroup->options as $opt) {
                    $formatNames[] = $opt->name;
                }
                $result['formats'] = $formatNames;
            } else {
                // Non-pivot constructor: flatten all paths into simple rows
                // Deduplicate by label+price (e.g. hard binding: multiple colors same price)
                $seen = [];
                foreach ($rootGroup->options as $rootOpt) {
                    $leaves = [];
                    $this->collectFullPaths(
                        $middleGroups,
                        null, // no fill group for simple services
                        $rootOpt,
                        [],
                        (float) $rootOpt->price_markup,
                        $leaves,
                    );

                    $rootLabel = $this->extractLastPart($rootOpt->name);

                    if (empty($leaves)) {
                        // Leaf option with direct price
                        $price = (float) $rootOpt->price_markup;
                        if ($price > 0) {
                            $key = $rootLabel . '|' . $price;
                            if (!isset($seen[$key])) {
                                $seen[$key] = true;
                                $result['simple_rows'][] = [
                                    'label' => $rootLabel,
                                    'price' => $price,
                                ];
                            }
                        }
                    } else {
                        foreach ($leaves as $leaf) {
                            if ($leaf['price'] <= 0) {
                                continue;
                            }

                            // Build label from path (includes size/pages info)
                            $pathLabel = !empty($leaf['path_key'])
                                ? $this->buildSimpleLabel($rootLabel, $leaf['path_key'], $leaf['fill'])
                                : ($leaf['fill'] !== '—'
                                    ? $rootLabel . ' — ' . $leaf['fill']
                                    : $rootLabel);

                            $key = $pathLabel . '|' . $leaf['price'];
                            if (!isset($seen[$key])) {
                                $seen[$key] = true;
                                $result['simple_rows'][] = [
                                    'label' => $pathLabel,
                                    'price' => $leaf['price'],
                                ];
                            }
                        }
                    }
                }
                continue;
            }

            // For each format, collect all prices with cascade path
            $allLeaves = [];
            foreach ($rootGroup->options as $formatOpt) {
                $leaves = [];
                $this->collectFullPaths(
                    $middleGroups,
                    $fillGroup,
                    $formatOpt,
                    [],
                    (float) $formatOpt->price_markup,
                    $leaves,
                );

                foreach ($leaves as $leaf) {
                    $key = $leaf['path_key'];
                    if (!isset($allLeaves[$key])) {
                        $allLeaves[$key] = [
                            'paper_group' => $leaf['paper_group'],
                            'density'     => $leaf['density'],
                            // A leaf reached through a path is named by that
                            // path — lamination's three film types are only
                            // told apart there. A leaf sitting on the format
                            // option itself has nothing else to be called.
                            'fill'        => $leaf['fill'] === '—' && ! empty($leaf['path_key']) && ! $leaf['is_root_leaf']
                                ? $leaf['path_key']
                                : $leaf['fill'],
                            'prices'      => [],
                            'sort'        => $leaf['sort'],
                        ];
                    }
                    $allLeaves[$key]['prices'][$formatOpt->name] = $leaf['price'];
                }
            }

            // Consolidate dense papers for cleaner price list:
            // 1. Remove coated paper (currently out of stock)
            // 2. Merge pastel into "Щільний" (same commercial prices)
            // 3. Deduplicate by paper_group + fill
            //
            // All of this is about paper. A category priced on something else
            // — lamination is priced per film type — has no paper group to
            // consolidate on, and running it through anyway collapsed every
            // row into the first one.
            $hasPaperGroups = false;
            foreach ($allLeaves as $leaf) {
                if ($leaf['paper_group'] !== '') {
                    $hasPaperGroups = true;
                    break;
                }
            }

            if ($hasPaperGroups) {
                $allLeaves = array_filter(
                    $allLeaves,
                    fn ($leaf) => $leaf['paper_group'] !== 'Щільний матов./глянц.',
                );

                foreach ($allLeaves as &$leaf) {
                    if ($leaf['paper_group'] === 'Кольоровий пастельний') {
                        $leaf['paper_group'] = 'Щільний';
                    }
                }
                unset($leaf);
            }

            // Merging by paper group + row label is what turns "А4 costs 6,
            // А3 costs 10" into one row with two columns. Rows that carry a
            // distinct label of their own keep their own line.
            $dedupedLeaves = [];
            foreach ($allLeaves as $leaf) {
                $dedupeKey = $leaf['paper_group'] . '|' . $leaf['fill'];
                if (!isset($dedupedLeaves[$dedupeKey])) {
                    if ($leaf['paper_group'] === 'Щільний') {
                        $leaf['density'] = '160/200';
                    }
                    $dedupedLeaves[$dedupeKey] = $leaf;
                } else {
                    // Merge format prices from duplicate density
                    foreach ($leaf['prices'] as $fmt => $price) {
                        if (!isset($dedupedLeaves[$dedupeKey]['prices'][$fmt])) {
                            $dedupedLeaves[$dedupeKey]['prices'][$fmt] = $price;
                        }
                    }
                }
            }
            $allLeaves = $dedupedLeaves;

            if (!empty($allLeaves)) {
                $result['type'] = 'pivot';

                // "Prices are for single-sided; double-sided is ×2" only means
                // something where sides exist — that is, where the constructor
                // has a Заповненість group. Lamination is priced per film and
                // scanning per sheet; the note is nonsense under either, and
                // they only started reaching this line once their hardcoded
                // blocks were removed.
                $result['print_note'] = $fillGroup !== null;

                // Sort leaves
                uasort($allLeaves, fn ($a, $b) => $a['sort'] <=> $b['sort']);

                // Group by paper_group
                $grouped = [];
                foreach ($allLeaves as $leaf) {
                    $pg = $leaf['paper_group'] ?: 'Стандартний';
                    if (!isset($grouped[$pg])) {
                        $grouped[$pg] = [];
                    }
                    $grouped[$pg][] = $leaf;
                }

                $result['paper_groups'] = [];
                foreach ($grouped as $pgName => $rows) {
                    $result['paper_groups'][] = [
                        'name' => $pgName,
                        'density' => $rows[0]['density'] ?? '',
                        'rows' => array_map(fn ($r) => [
                            'fill'   => $r['fill'],
                            'prices' => $r['prices'],
                        ], $rows),
                    ];
                }
            }
        }

        // Binding is presented as a tier table rather than a flat list. Only
        // the presentation is keyed off the name — the bands and the prices
        // are whatever the constructor's own options say, so renaming a
        // category costs a nicer table, never a wrong number.
        if (in_array($category->name, self::TIER_CATEGORIES, true) && ! empty($result['simple_rows'])) {
            $result['type']              = 'tiers';
            $result['tier_header']       = 'Варіант';
            $result['tier_price_header'] = 'грн./екз.';
            $result['tier_rows']         = $result['simple_rows'];
            // The template prefers simple_rows when both are present.
            $result['simple_rows']       = [];
        }

        // Handle empty categories
        if ($result['type'] === 'simple' && empty($result['simple_rows'])) {
            if ($category->name === 'Палітурка тверда') {
                $result['type'] = 'request';
                return $result;
            }
            return null;
        }

        return $result;
    }

    /**
     * Collect all leaf paths including fill, keyed by format-stripped path.
     *
     * For print categories: walks Format → [Paper →] Sides → Fill
     * Only follows single-sided (1+0 / 4+0) paths for the price list.
     * Note: duplex pricing = single-sided × 2 (noted in UI).
     */
    private function collectFullPaths(
        array $middleGroups,
        $fillGroup,
        $currentOption,
        array $pathParts,
        float $totalMarkup,
        array &$leaves,
        int $sortBase = 0,
    ): void {
        // Find required children in middle groups
        $children = [];
        foreach ($middleGroups as $group) {
            if (!$group->is_required) {
                continue;
            }
            foreach ($group->options as $opt) {
                $dep = $opt->depends_on;
                if (
                    is_array($dep)
                    && isset($dep['option_ids'])
                    && is_array($dep['option_ids'])
                    && in_array($currentOption->id, $dep['option_ids'])
                ) {
                    $children[] = ['group' => $group, 'option' => $opt];
                }
            }
        }

        if (empty($children)) {
            // Leaf node — collect with fill variants
            $cleanName = $this->extractLastPart($currentOption->name);

            // Determine paper group and density from path
            $paperGroup = '';
            $density = '';
            foreach ($pathParts as $part) {
                if (str_contains($part, 'г/м')) {
                    $paperGroup = $this->classifyPaper($part);
                    $density = $this->extractDensity($part);
                }
            }

            if ($fillGroup) {
                // Collect fill variants
                $fillSort = 0;
                foreach ($fillGroup->options as $fopt) {
                    $dep = $fopt->depends_on;
                    if (
                        is_array($dep)
                        && isset($dep['option_ids'])
                        && in_array($currentOption->id, $dep['option_ids'])
                    ) {
                        $fillLabel = $this->extractLastPart($fopt->name);
                        $fillPrice = round($totalMarkup + (float) $fopt->price_markup, 2);

                        $pathKey = implode('|', array_merge($pathParts, [$fillLabel]));
                        $leaves[] = [
                            'path_key'     => $pathKey,
                            'paper_group'  => $paperGroup,
                            'density'      => $density,
                            'fill'         => $fillLabel,
                            'price'        => $fillPrice,
                            'sort'         => $sortBase * 100 + $fillSort++,
                            'is_root_leaf' => false,
                        ];
                    }
                }
            }

            // If no fill, add as single row
            if (!$fillGroup || empty(array_filter($leaves, fn ($l) => str_contains($l['path_key'], implode('|', $pathParts))))) {
                $pathKey = implode('|', $pathParts);
                if ($totalMarkup > 0) {
                    $leaves[] = [
                        'path_key'    => $pathKey ?: $cleanName,
                        'paper_group' => $paperGroup,
                        'density'     => $density,
                        'fill'        => '—',
                        'price'       => round($totalMarkup, 2),
                        'sort'        => $sortBase * 100,
                        // Nothing was walked to get here: the price sits on the
                        // format option itself, so the row has no name beyond
                        // the format columns it will be merged into.
                        'is_root_leaf' => empty($pathParts),
                    ];
                }
            }

            return;
        }

        // Skip paper branches with zero markup for BW
        // (Paper options that only link inventory, not pricing)
        // AND follow only single-sided (1+0, 4+0) for cleaner price list
        $childSort = 0;
        foreach ($children as $child) {
            $opt = $child['option'];
            $cleanName = $this->extractLastPart($opt->name);
            $optMarkup = (float) $opt->price_markup;

            // Skip double-sided options for price list (single-sided only)
            if (in_array($cleanName, ['1+1', '4+4'])) {
                continue;
            }

            // Skip zero-markup paper options (they don't affect price)
            // Use cleanName to avoid false matches on cascaded names like "Папір 80 г/м²: 4+0"
            if ($optMarkup == 0 && str_contains($cleanName, 'г/м')) {
                $childSort++;
                continue;
            }

            // For sides options (1+0, 4+0) — don't add to path display
            $newPathParts = $pathParts;
            if (!in_array($cleanName, ['1+0', '4+0'])) {
                $newPathParts[] = $cleanName;
            }

            $this->collectFullPaths(
                $middleGroups,
                $fillGroup,
                $opt,
                $newPathParts,
                $totalMarkup + $optMarkup,
                $leaves,
                $sortBase + $childSort++,
            );
        }
    }

    /**
     * Classify paper type for grouping.
     */
    private function classifyPaper(string $name): string
    {
        if (str_contains($name, 'крейдований') || str_contains($name, 'крейд')) {
            return 'Щільний матов./глянц.';
        }
        if (str_contains($name, 'паст.')) {
            return 'Кольоровий пастельний';
        }
        $density = $this->extractDensity($name);
        if ($density && (int) $density >= 150) {
            return 'Щільний';
        }
        return 'Стандартний';
    }

    /**
     * Extract density number from paper name.
     */
    private function extractDensity(string $name): string
    {
        if (preg_match('/(\d+)\s*г\/м/', $name, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Extract the last meaningful part from an option name.
     */
    private function extractLastPart(string $name): string
    {
        $parts = explode(': ', $name);
        return end($parts);
    }

    /**
     * Build a label for simple (non-pivot) rows from path_key.
     *
     * For hard binding, path_key looks like "3.5 мм (0-35 стор.)|Червоний"
     * We want: "Комплектна: 3.5 мм (0-35 стор.)" — strip colors from path.
     */
    private function buildSimpleLabel(string $rootLabel, string $pathKey, string $fill): string
    {
        $parts = explode('|', $pathKey);

        // Filter out color names and parts identical to rootLabel (prevents duplication)
        $colors = ['Червоний', 'Синій', 'Чорний', 'Сірий', 'Червона', 'Синя'];
        $meaningful = array_filter(
            $parts,
            fn ($p) => !in_array($p, $colors) && $p !== $rootLabel,
        );

        if (!empty($meaningful)) {
            $sizePart = implode(' — ', $meaningful);
            $label = $rootLabel . ': ' . $sizePart;
        } else {
            $label = $rootLabel;
        }

        if ($fill !== '—') {
            $label .= ' — ' . $fill;
        }

        return $label;
    }
}
