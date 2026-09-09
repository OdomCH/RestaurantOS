<?php

namespace Database\Seeders;

use App\Enums\UnitFamily;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * The measurement units every deployment needs. Base units first, because the
 * derived ones point at them.
 *
 * Conversion never crosses a family: ml to l is arithmetic, ml to g needs a
 * density the system does not model and is rejected.
 */
class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $bases = [
            ['code' => 'kg', 'name' => 'Kilogram', 'family' => UnitFamily::Mass->value, 'precision_digits' => 3],
            ['code' => 'l', 'name' => 'Litre', 'family' => UnitFamily::Volume->value, 'precision_digits' => 3],
            ['code' => 'pcs', 'name' => 'Piece', 'family' => UnitFamily::Count->value, 'precision_digits' => 0],
        ];

        foreach ($bases as $base) {
            Unit::updateOrCreate(
                ['code' => $base['code']],
                $base + ['base_unit_id' => null, 'conversion_factor' => 1],
            );
        }

        $derived = [
            ['code' => 'g', 'name' => 'Gram', 'family' => UnitFamily::Mass->value, 'base' => 'kg', 'conversion_factor' => 0.001, 'precision_digits' => 1],
            ['code' => 'ml', 'name' => 'Millilitre', 'family' => UnitFamily::Volume->value, 'base' => 'l', 'conversion_factor' => 0.001, 'precision_digits' => 1],
        ];

        foreach ($derived as $unit) {
            $baseId = Unit::where('code', $unit['base'])->value('id');
            unset($unit['base']);

            Unit::updateOrCreate(
                ['code' => $unit['code']],
                $unit + ['base_unit_id' => $baseId],
            );
        }

        $this->command?->info('Seeded '.Unit::count().' units.');
    }
}
