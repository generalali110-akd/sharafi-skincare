<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Models\ProductVariant;
use Illuminate\Console\Command;

final class E2eSetStockCommand extends Command
{
    protected $signature = 'e2e:set-stock {sku} {onHand}';

    protected $description = 'Set deterministic inventory for browser conflict tests';

    public function handle(): int
    {
        if (! app()->environment('testing')) {
            $this->error('This command is restricted to the testing environment.');

            return self::FAILURE;
        }

        $sku = trim((string) $this->argument('sku'));
        $onHand = filter_var($this->argument('onHand'), FILTER_VALIDATE_INT);
        if ($sku === '' || $onHand === false || $onHand < 0 || $onHand > 1_000_000) {
            $this->error('Invalid SKU or on-hand quantity.');

            return self::FAILURE;
        }

        $variant = ProductVariant::query()->where('sku', $sku)->first();
        if (! $variant) {
            $this->error('Variant not found.');

            return self::FAILURE;
        }

        $inventory = InventoryItem::query()->where('variant_id', $variant->id)->firstOrFail();
        if ($inventory->reserved > $onHand) {
            $this->error('onHand cannot be lower than currently reserved quantity in this deterministic helper.');

            return self::FAILURE;
        }

        $inventory->forceFill(['on_hand' => $onHand])->save();
        $this->info("{$sku} on_hand={$onHand}");

        return self::SUCCESS;
    }
}
