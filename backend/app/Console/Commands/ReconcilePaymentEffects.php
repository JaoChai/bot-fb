<?php

namespace App\Console\Commands;

use App\Models\PaymentEffect;
use App\Services\CommerceSafety\PaymentEffectDispatcher;
use Illuminate\Console\Command;

class ReconcilePaymentEffects extends Command
{
    protected $signature = 'payment-effects:reconcile';

    protected $description = 'Redrive safe durable payment effects; report uncertain and exhausted effects for manual review';

    public function handle(PaymentEffectDispatcher $dispatcher): int
    {
        $counts = $dispatcher->reconcile();
        $this->info("queued={$counts['queued']} uncertain={$counts['uncertain']} exhausted={$counts['exhausted']}");
        PaymentEffect::where('state', 'uncertain')->select(['id', 'kind', 'last_error_code'])
            ->chunkById(100, function ($effects): void {
                foreach ($effects as $effect) {
                    $this->line("uncertain {$effect->id} {$effect->kind} {$effect->last_error_code}");
                }
            });

        return self::SUCCESS;
    }
}
