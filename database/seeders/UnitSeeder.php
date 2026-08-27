<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Seeds a common set of measurement units for EVERY company (tenant).
 * Idempotent: uses firstOrCreate per (company, unit) so re-running never
 * duplicates. New companies won't get these automatically — re-run this
 * seeder, or create units per-company in the app.
 */
class UnitSeeder extends Seeder
{
    /** [unit_name, symbol] */
    private const UNITS = [
        ['Piece', 'pcs'],
        ['Pack', 'pack'],
        ['Box', 'box'],
        ['Dozen', 'dz'],
        ['Pair', 'pr'],
        ['Set', 'set'],
        ['Kilogram', 'kg'],
        ['Gram', 'g'],
        ['Milligram', 'mg'],
        ['Pound', 'lb'],
        ['Ounce', 'oz'],
        ['Litre', 'L'],
        ['Millilitre', 'ml'],
        ['Gallon', 'gal'],
        ['Metre', 'm'],
        ['Centimetre', 'cm'],
        ['Millimetre', 'mm'],
        ['Inch', 'in'],
        ['Foot', 'ft'],
        ['Roll', 'roll'],
        ['Bottle', 'btl'],
        ['Bag', 'bag'],
        ['Carton', 'ctn'],
        ['Unit', 'unit'],
    ];

    public function run(): void
    {
        Company::query()->select('id')->chunkById(200, function ($companies) {
            foreach ($companies as $company) {
                foreach (self::UNITS as [$name, $symbol]) {
                    Unit::firstOrCreate(
                        ['company_id' => $company->id, 'unit_name' => $name],
                        ['symbol' => $symbol, 'status' => true]
                    );
                }
            }
        });

        $this->command?->info('Seeded common units for all companies.');
    }
}
