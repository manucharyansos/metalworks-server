<?php

namespace App\Http\Controllers\Api\Quote;

use App\Http\Controllers\Controller;
use App\Models\{PricingMaterial, PricingSetting, PricingThickness};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The company's price list for instant estimates, saved as a whole. */
class PricingController extends Controller
{
    private const MONEY = 'required|numeric|min:0|max:100000000';

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'required|array',
            'settings.enabled' => 'required|boolean',
            'settings.bend_price' => self::MONEY, 'settings.paint_price_m2' => self::MONEY, 'settings.primer_price_m2' => self::MONEY,
            'settings.paint_min_part' => self::MONEY, 'settings.setup_fee' => self::MONEY, 'settings.min_order' => self::MONEY,
            'settings.waste_percent' => 'required|numeric|min:0|max:200',
            'settings.discounts' => 'present|array|max:10',
            'settings.discounts.*.min_qty' => 'required|integer|min:2|max:1000000|distinct',
            'settings.discounts.*.percent' => 'required|numeric|min:0|max:90',
            'settings.vat_included' => 'required|boolean',
            'settings.customer_note' => 'nullable|string|max:2000',
            'materials' => 'present|array|max:100',
            'materials.*.id' => 'nullable|integer',
            'materials.*.name' => 'required|string|max:120|distinct:ignore_case',
            'materials.*.density' => 'required|numeric|min:100|max:30000',
            'materials.*.price_per_kg' => self::MONEY,
            'materials.*.is_active' => 'required|boolean',
            'materials.*.thicknesses' => 'present|array|max:60',
            'materials.*.thicknesses.*.id' => 'nullable|integer',
            'materials.*.thicknesses.*.thickness' => 'required|numeric|min:0.1|max:300',
            'materials.*.thicknesses.*.cut_price_per_m' => self::MONEY,
            'materials.*.thicknesses.*.pierce_price' => self::MONEY,
            'materials.*.thicknesses.*.is_active' => 'required|boolean',
        ]);

        DB::transaction(function () use ($data) {
            $settings = PricingSetting::query()->lockForUpdate()->first() ?? new PricingSetting();
            $discounts = collect($data['settings']['discounts'])->map(fn ($tier) => ['min_qty' => (int) $tier['min_qty'], 'percent' => round((float) $tier['percent'], 2)])
                ->sortBy('min_qty')->values()->all();
            $settings->fill(['discounts' => $discounts, 'customer_note' => trim((string) ($data['settings']['customer_note'] ?? '')) ?: null]
                + collect($data['settings'])->only(['enabled', 'bend_price', 'paint_price_m2', 'primer_price_m2', 'paint_min_part', 'setup_fee', 'min_order', 'waste_percent', 'vat_included'])->all());
            $settings->save();

            $keep = [];
            foreach ($data['materials'] as $position => $row) {
                $material = !empty($row['id']) ? PricingMaterial::lockForUpdate()->find((int) $row['id']) : null;
                if (!empty($row['id']) && !$material) throw ValidationException::withMessages(["materials.$position.id" => ['Նյութը չի գտնվել։ Թարմացրեք էջը։']]);
                $material ??= new PricingMaterial();
                $material->fill(['name' => trim($row['name']), 'density' => $row['density'], 'price_per_kg' => $row['price_per_kg'], 'is_active' => $row['is_active'], 'sort' => $position])->save();
                $keep[] = $material->id;
                $thicknesses = [];
                $seen = [];
                foreach ($row['thicknesses'] as $index => $line) {
                    $value = round((float) $line['thickness'], 2);
                    if (in_array($value, $seen, true)) throw ValidationException::withMessages(["materials.$position.thicknesses.$index.thickness" => ['Այս հաստությունն արդեն կա ցանկում։']]);
                    $seen[] = $value;
                    $thickness = !empty($line['id']) ? PricingThickness::where('pricing_material_id', $material->id)->lockForUpdate()->find((int) $line['id']) : null;
                    if (!empty($line['id']) && !$thickness) throw ValidationException::withMessages(["materials.$position.thicknesses.$index.id" => ['Հաստությունը չի գտնվել։ Թարմացրեք էջը։']]);
                    $thickness ??= new PricingThickness(['pricing_material_id' => $material->id]);
                    $thickness->fill(['thickness' => $value, 'cut_price_per_m' => $line['cut_price_per_m'], 'pierce_price' => $line['pierce_price'], 'is_active' => $line['is_active']])->save();
                    $thicknesses[] = $thickness->id;
                }
                // A removed thickness leaves drafts without a material choice, never a wrong price.
                PricingThickness::where('pricing_material_id', $material->id)->whereNotIn('id', $thicknesses ?: [0])->get()->each->delete();
            }
            PricingMaterial::whereNotIn('id', $keep ?: [0])->get()->each->delete();
        }, 3);

        return response()->json($this->payload());
    }

    private function payload(): array
    {
        $settings = PricingSetting::current();
        return [
            'settings' => [
                'enabled' => (bool) $settings->enabled, 'bend_price' => $settings->bend_price, 'paint_price_m2' => $settings->paint_price_m2,
                'primer_price_m2' => $settings->primer_price_m2, 'paint_min_part' => $settings->paint_min_part, 'setup_fee' => $settings->setup_fee,
                'min_order' => $settings->min_order, 'waste_percent' => $settings->waste_percent, 'discounts' => array_values($settings->discounts ?? []),
                'vat_included' => (bool) $settings->vat_included, 'customer_note' => $settings->customer_note,
            ],
            'materials' => PricingMaterial::with('thicknesses')->orderBy('sort')->orderBy('id')->get()->map(fn (PricingMaterial $material) => [
                'id' => $material->id, 'name' => $material->name, 'density' => $material->density, 'price_per_kg' => $material->price_per_kg,
                'is_active' => $material->is_active,
                'thicknesses' => $material->thicknesses->map(fn (PricingThickness $t) => [
                    'id' => $t->id, 'thickness' => $t->thickness, 'cut_price_per_m' => $t->cut_price_per_m, 'pierce_price' => $t->pierce_price, 'is_active' => $t->is_active,
                ])->values(),
            ])->values(),
        ];
    }
}
