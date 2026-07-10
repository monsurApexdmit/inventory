<?php

namespace App\Console\Commands;

use App\Services\Barcode\BarcodeServicePOS;
use Illuminate\Console\Command;

class GenerateMissingBarcodes extends Command
{
    protected $signature = 'barcode:generate-missing';
    protected $description = 'Generate missing barcodes for products and variants';

    public function handle()
    {
        $this->info('🔄 Generating missing barcodes...');

        try {
            $service = new BarcodeServicePOS();
            $result = $service->generateMissingBarcodes();

            $this->info('✅ Barcode generation complete!');
            $this->line('');
            $this->line('📊 Results:');
            $this->line('  Products generated: ' . $result['products_generated']);
            $this->line('  Variants generated: ' . $result['variants_generated']);
            $this->line('  Total generated: ' . $result['total']);

            if (!empty($result['errors'])) {
                $this->error('  Errors: ' . count($result['errors']));
                foreach ($result['errors'] as $error) {
                    $this->error('    - ' . $error);
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
