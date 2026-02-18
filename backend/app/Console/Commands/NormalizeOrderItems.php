<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\OrderItem;
use App\Services\OrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NormalizeOrderItems extends Command
{
    protected $signature = 'orders:normalize-items
                            {--dry-run : Preview changes without writing to DB}';

    protected $description = 'Normalize product_name, category, and variant for existing order_items';

    protected int $updated = 0;

    protected int $skipped = 0;

    protected int $ambiguous = 0;

    protected int $resolvedFromConversation = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $items = OrderItem::with(['order.conversation', 'order.message'])->get();
        $this->info("Found {$items->count()} order items to process.");

        $changes = [];

        foreach ($items as $item) {
            $result = $this->normalizeItem($item);

            if ($result === null) {
                $this->skipped++;

                continue;
            }

            $changes[] = $result;
        }

        // Display preview
        if (! empty($changes)) {
            $this->newLine();
            $this->info('Changes preview:');
            $this->table(
                ['ID', 'Old Name', 'New Name', 'Variant', 'Old Cat', 'New Cat', 'Source'],
                array_map(fn ($c) => [
                    $c['id'],
                    mb_strimwidth($c['old_name'], 0, 35, '...'),
                    $c['new_name'],
                    $c['variant'] ?? '-',
                    $c['old_category'],
                    $c['new_category'],
                    $c['source'],
                ], $changes)
            );
        }

        // Apply changes
        if (! $dryRun && ! empty($changes)) {
            $this->newLine();
            $bar = $this->output->createProgressBar(count($changes));
            $bar->start();

            foreach ($changes as $change) {
                OrderItem::where('id', $change['id'])->update([
                    'product_name' => $change['new_name'],
                    'category' => $change['new_category'],
                    'variant' => $change['variant'],
                ]);
                $this->updated++;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
        }

        // Summary
        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Total items', $items->count()],
            ['Would change / Changed', count($changes)],
            ['Already correct (skipped)', $this->skipped],
            ['Ambiguous (resolved from conversation)', $this->resolvedFromConversation],
            ['Ambiguous (unresolvable)', $this->ambiguous],
        ]);

        if (! $dryRun) {
            Log::info('NormalizeOrderItems completed', [
                'updated' => $this->updated,
                'skipped' => $this->skipped,
                'ambiguous' => $this->ambiguous,
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{id: int, old_name: string, new_name: string, variant: string|null, old_category: string, new_category: string, source: string}|null
     */
    protected function normalizeItem(OrderItem $item): ?array
    {
        $oldName = $item->product_name;
        $oldCategory = $item->category;

        $normalized = OrderService::normalizeProductName($oldName);
        $source = 'keyword';

        // Handle garbage names by looking at conversation
        $garbageNames = ['Unknown', '[รายการสินค้า]', '[รายการสินค้าที่อยู่ตะกร้า]'];
        $isGarbage = in_array($oldName, $garbageNames, true);

        // If result is ambiguous "Nolimit" or garbage, try conversation lookback
        if ($normalized['name'] === 'Nolimit' || $normalized['name'] === 'Unknown' || $isGarbage) {
            $resolved = $this->resolveFromConversation($item);
            if ($resolved !== null) {
                $normalized = $resolved;
                $source = 'conversation';
                $this->resolvedFromConversation++;
            } else {
                $this->ambiguous++;
                $source = 'ambiguous';
                // Keep as "Nolimit" if we can't resolve (not garbage)
                if ($isGarbage) {
                    $normalized = ['name' => 'Unknown', 'variant' => null, 'category' => 'nolimit'];
                }
            }
        }

        // Skip if nothing changed
        if ($normalized['name'] === $oldName
            && $normalized['category'] === $oldCategory
            && $normalized['variant'] === $item->variant) {
            return null;
        }

        return [
            'id' => $item->id,
            'old_name' => $oldName,
            'new_name' => $normalized['name'],
            'variant' => $normalized['variant'],
            'old_category' => $oldCategory,
            'new_category' => $normalized['category'],
            'source' => $source,
        ];
    }

    /**
     * Look back at conversation messages to resolve ambiguous product names.
     *
     * @return array{name: string, variant: string|null, category: string}|null
     */
    protected function resolveFromConversation(OrderItem $item): ?array
    {
        $order = $item->order;
        if (! $order || ! $order->conversation_id || ! $order->message_id) {
            return null;
        }

        // Get messages before the confirmation message in the same conversation
        $messages = Message::where('conversation_id', $order->conversation_id)
            ->where('id', '<', $order->message_id)
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('content');

        if ($messages->isEmpty()) {
            return null;
        }

        $combined = $messages->implode(' ');
        $lower = mb_strtolower($combined);

        // Extract variant from conversation context
        $variant = null;
        if (mb_strpos($lower, 'ผูกบัตร') !== false) {
            $variant = 'ผูกบัตร';
        } elseif (mb_strpos($lower, 'เติมเงิน') !== false) {
            $variant = 'เติมเงิน';
        }

        // Try to determine product type
        if (mb_strpos($lower, 'ไก่') !== false || mb_strpos($lower, 'g3d') !== false) {
            return ['name' => 'G3D', 'variant' => $variant, 'category' => 'g3d'];
        }
        if (mb_strpos($lower, 'เพจ') !== false || mb_strpos($lower, 'page') !== false) {
            return ['name' => 'Page', 'variant' => null, 'category' => 'page'];
        }
        if (mb_strpos($lower, 'bm') !== false || mb_strpos($lower, 'บีเอ็ม') !== false || mb_strpos($lower, 'บัญชีธุรกิจ') !== false) {
            return ['name' => 'Nolimit BM', 'variant' => $variant, 'category' => 'nolimit'];
        }
        if (mb_strpos($lower, 'personal') !== false || mb_strpos($lower, 'ส่วนตัว') !== false) {
            return ['name' => 'Nolimit Personal', 'variant' => $variant, 'category' => 'nolimit'];
        }

        return null;
    }
}
