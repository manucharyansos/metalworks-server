<?php

namespace App\Support;

use App\Models\{Factory, PricingSetting, PricingThickness, QuoteRequest, QuoteRequestItem};
use Illuminate\Support\Collection;

/**
 * Instant estimates from the company's price list. For one part:
 *   material = sheet weight (bounding rectangle plus waste) × price per kg
 *   cutting  = cut length × price per metre + pierces × pierce price (by thickness)
 *   bending  = bends × bend price
 *   painting = max(both sides × price per m², minimum per part), plus primer
 * The quantity discount applies to the line; the request adds the setup fee and
 * is raised to the minimum order. What the list cannot price (no flat drawing,
 * welding, rolling, tube cutting, a material outside the list) stays open for
 * the manager, who confirms the final price either way.
 */
final class QuotePricing
{
    private const CUT = ['DXF'];
    private const BEND = ['DLD'];

    /** @return array{0: PricingSetting, 1: Collection} */
    public static function context(): array
    {
        return [PricingSetting::current(), Factory::workshops()->get(['factories.id', 'factories.value', 'factories.name'])->keyBy('id')];
    }

    public static function item(QuoteRequestItem $item, array $context): array
    {
        [$settings, $workshops] = $context;
        $reasons = [];
        if (!$settings->enabled) $reasons[] = 'disabled';
        $metrics = $item->metrics ?? [];
        if (($metrics['status'] ?? null) !== 'ok') $reasons[] = 'drawing';
        $thickness = $item->pricing_thickness_id ? PricingThickness::with('material')->find($item->pricing_thickness_id) : null;
        if (!$thickness || !$thickness->is_active || !$thickness->material?->is_active) $reasons[] = 'material';
        $bend = $paint = false;
        foreach ($item->workshop_ids ?? [] as $id) {
            $factory = $workshops->get((int) $id);
            $value = strtoupper(trim((string) $factory?->value));
            if (in_array($value, self::CUT, true)) continue;
            if (in_array($value, self::BEND, true)) { $bend = true; continue; }
            if ($factory && TaskPaint::isPowder($factory)) { $paint = true; continue; }
            $reasons[] = 'service';
        }
        $bends = $item->bends ?? (int) ($metrics['bends'] ?? 0);
        if ($bend && $bends < 1) $reasons[] = 'bends';
        if ($reasons) return ['status' => 'on_request', 'reasons' => array_values(array_unique($reasons))];

        $material = $thickness->material;
        $outline = $metrics['width_mm'] * $metrics['height_mm'] / 1e6; // m²
        $net = isset($metrics['area_mm2']) && $metrics['area_mm2'] > 0 ? $metrics['area_mm2'] / 1e6 : $outline;
        $sheetKg = $outline * (1 + max(0, $settings->waste_percent) / 100) * $thickness->thickness / 1000 * $material->density;
        $paintArea = 2 * $net;
        $costs = [
            'material' => $sheetKg * $material->price_per_kg,
            'cutting' => $metrics['cut_length_mm'] / 1000 * $thickness->cut_price_per_m + $metrics['pierces'] * $thickness->pierce_price,
            'bending' => $bend ? $bends * $settings->bend_price : 0.0,
            'painting' => $paint ? max($paintArea * $settings->paint_price_m2, $settings->paint_min_part)
                + ($item->primer_required ? $paintArea * $settings->primer_price_m2 : 0.0) : 0.0,
        ];
        $base = array_sum($costs);
        $discount = self::discount($settings, (int) $item->quantity);
        $unit = (int) round($base * (1 - $discount / 100));
        return [
            'status' => 'priced', 'unit' => $unit, 'line' => $unit * (int) $item->quantity, 'discount_percent' => $discount,
            'breakdown' => array_map(fn ($cost) => (int) round($cost), $costs),
            'weight_kg' => round($net * $thickness->thickness / 1000 * $material->density, 3),
            'paint_area_m2' => $paint ? round($paintArea, 3) : null,
            'bends' => $bend ? $bends : null,
            'breaks' => self::breaks($settings, $base),
        ];
    }

    public static function quote(QuoteRequest $quote, ?array $context = null): array
    {
        $context ??= self::context();
        [$settings] = $context;
        $items = [];
        $subtotal = 0;
        $priced = 0;
        foreach ($quote->items as $item) {
            $price = self::item($item, $context);
            $items[$item->id] = $price;
            if ($price['status'] === 'priced') { $subtotal += $price['line']; $priced++; }
        }
        $count = count($items);
        $complete = $count > 0 && $priced === $count;
        $setup = $priced ? (int) round($settings->setup_fee) : 0;
        $total = $subtotal + $setup;
        // The minimum order only makes sense for a fully priced request.
        $minimum = $complete && $total < $settings->min_order;
        if ($minimum) $total = (int) round($settings->min_order);
        return [
            'enabled' => (bool) $settings->enabled, 'items' => $items, 'subtotal' => $subtotal, 'setup_fee' => $setup,
            'minimum_applied' => $minimum, 'total' => $priced ? $total : null, 'complete' => $complete,
            'priced' => $priced, 'count' => $count, 'currency' => 'AMD', 'vat_included' => (bool) $settings->vat_included,
        ];
    }

    private static function discount(PricingSetting $settings, int $quantity): float
    {
        $best = 0.0;
        foreach ($settings->discounts ?? [] as $tier) {
            if ($quantity >= (int) ($tier['min_qty'] ?? PHP_INT_MAX)) $best = max($best, (float) ($tier['percent'] ?? 0));
        }
        return $best;
    }

    /** The unit price at each quantity step, as a small table for the customer. */
    private static function breaks(PricingSetting $settings, float $base): array
    {
        $steps = [1];
        foreach ($settings->discounts ?? [] as $tier) $steps[] = (int) ($tier['min_qty'] ?? 0);
        $steps = array_values(array_unique(array_filter($steps, fn ($n) => $n >= 1)));
        sort($steps);
        return array_map(fn ($quantity) => ['quantity' => $quantity, 'unit' => (int) round($base * (1 - self::discount($settings, $quantity) / 100))], $steps);
    }
}
