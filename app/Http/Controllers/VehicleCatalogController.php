<?php

namespace App\Http\Controllers;

use App\Models\VehicleCatalogEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleCatalogController extends Controller
{
    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 15;

    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => ['required', 'string', 'min:2', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Neispravan upit za pretragu kataloga vozila.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $limit = $validated['limit'] ?? self::DEFAULT_LIMIT;

        $entries = VehicleCatalogEntry::query()
            ->search(trim($validated['q']))
            ->orderBy('make')
            ->orderBy('model')
            ->orderBy('year_from')
            ->orderBy('variant_name')
            ->limit($limit)
            ->get();

        return response()->json([
            'results' => $entries->map(fn (VehicleCatalogEntry $entry) => $this->toResult($entry))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toResult(VehicleCatalogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'label' => $entry->displayLabel(),
            'make' => $entry->make,
            'model' => $entry->model,
            'generation' => $entry->generation,
            'platform_code' => $entry->platform_code,
            'variant_name' => $entry->variant_name,
            'trim_name' => $entry->trim_name,
            'year_from' => $entry->year_from,
            'year_to' => $entry->year_to,
            'body_type' => $entry->body_type,
            'fuel_type' => $entry->fuel_type,
            'transmission_type' => $entry->transmission_type,
            'engine_code' => $entry->engine_code,
            'power_kw' => $entry->power_kw,
            'power_hp' => $entry->power_hp,
            'displacement_cc' => $entry->displacement_cc,
            'vehicle_type_hint' => $entry->body_type,
            'production_year_hint' => $entry->year_to ?? $entry->year_from,
        ];
    }
}
