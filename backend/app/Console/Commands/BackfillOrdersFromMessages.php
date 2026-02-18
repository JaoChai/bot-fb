<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillOrdersFromMessages extends Command
{
    protected $signature = 'orders:backfill-from-messages
                            {--dry-run : Preview what would be created without writing to DB}
                            {--bot-id= : Only process messages for a specific bot}';

    protected $description = 'Backfill orders from historical bot payment confirmation messages';

    protected int $totalMessages = 0;

    protected int $created = 0;

    protected int $skipped = 0;

    protected int $errors = 0;

    protected int $itemsCreated = 0;

    protected float $totalRevenue = 0;

    protected Carbon $startTime;

    public function handle(): int
    {
        $this->startTime = now();
        $dryRun = $this->option('dry-run');
        $botId = $this->option('bot-id');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $query = Message::query()
            ->where('sender', 'bot')
            ->where('content', 'like', '%เงินเข้าแล้ว%')
            ->where('content', 'like', '%ออเดอร์%')
            ->where('content', 'like', '%ส่งใน%')
            ->whereHas('conversation');

        if ($botId) {
            $query->whereHas('conversation', fn ($q) => $q->where('bot_id', $botId));
        }

        $this->totalMessages = $query->count();

        if ($this->totalMessages === 0) {
            $this->info('No matching payment confirmation messages found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$this->totalMessages} payment confirmation messages.");

        if ($dryRun) {
            $this->processDryRun($query);

            return Command::SUCCESS;
        }

        $progressBar = $this->output->createProgressBar($this->totalMessages);
        $progressBar->start();

        $query->with(['conversation:id,bot_id,customer_profile_id,channel_type'])
            ->chunkById(100, function ($messages) use ($progressBar) {
                foreach ($messages as $message) {
                    $this->processMessage($message);
                    $progressBar->advance();
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        $this->logSummary();

        return Command::SUCCESS;
    }

    protected function processMessage(Message $message): void
    {
        // Dedup: skip if order already exists for this message
        if (Order::where('message_id', $message->id)->exists()) {
            $this->skipped++;

            return;
        }

        $conversation = $message->conversation;
        if (! $conversation) {
            $this->errors++;

            return;
        }

        $content = $message->content;

        // Extract amount
        $rawAmount = $this->extractAmount($content);
        if ($rawAmount === null) {
            $this->errors++;
            Log::warning('BackfillOrders: Could not extract amount', ['message_id' => $message->id]);

            return;
        }

        $amount = (float) str_replace([',', ' '], '', $rawAmount);
        if ($amount <= 0) {
            $this->errors++;

            return;
        }

        // Extract product lines
        $productLines = $this->extractProductLines($content);

        // Extract bank
        $bank = $this->extractBank($content);

        try {
            DB::transaction(function () use ($message, $conversation, $amount, $productLines, $bank) {
                // Use forceCreate to bypass $fillable for created_at/updated_at
                $order = Order::forceCreate([
                    'bot_id' => $conversation->bot_id,
                    'conversation_id' => $conversation->id,
                    'customer_profile_id' => $conversation->customer_profile_id,
                    'message_id' => $message->id,
                    'total_amount' => $amount,
                    'payment_method' => $bank,
                    'status' => 'completed',
                    'channel_type' => $conversation->channel_type,
                    'raw_extraction' => json_encode([
                        'source' => 'backfill',
                        'amount' => $amount,
                        'bank' => $bank,
                        'products' => $productLines,
                    ]),
                    'created_at' => $message->created_at,
                    'updated_at' => $message->created_at,
                ]);

                $isSingleItem = count($productLines) === 1;

                foreach ($productLines as $line) {
                    $order->items()->create([
                        'product_name' => $line['name'],
                        'category' => $line['category'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $isSingleItem ? $amount / $line['quantity'] : null,
                        'subtotal' => $isSingleItem ? $amount : null,
                    ]);
                    $this->itemsCreated++;
                }

                // If no product lines were extracted, create a generic item
                if (empty($productLines)) {
                    $order->items()->create([
                        'product_name' => 'Unknown',
                        'category' => 'nolimit',
                        'quantity' => 1,
                        'unit_price' => $amount,
                        'subtotal' => $amount,
                    ]);
                    $this->itemsCreated++;
                }
            });

            $this->created++;
            $this->totalRevenue += $amount;
        } catch (\Throwable $e) {
            $this->errors++;
            Log::error('BackfillOrders: Failed to create order', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function extractAmount(string $content): ?string
    {
        // Strip markdown bold markers for matching
        $clean = str_replace('**', '', $content);

        if (preg_match('/(?:เงินเข้าแล้ว\s*)([\d,]+\.?\d*)\s*บาท/u', $clean, $m)) {
            return $m[1];
        }
        if (preg_match('/([\d,]+\.?\d*)\s*บาท\s*✅/u', $clean, $m)) {
            return $m[1];
        }
        if (preg_match('/([\d,]+\.?\d*)\s*บาท/u', $clean, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<array{name: string, quantity: int, category: string}>
     */
    protected function extractProductLines(string $content): array
    {
        // Strip markdown bold markers
        $clean = str_replace('**', '', $content);

        if (! preg_match('/ออเดอร์[:\s]*\n([\s\S]*?)(?=\n\n|\n\[|\nส่งใน|$)/u', $clean, $m)) {
            return [];
        }

        $lines = array_filter(array_map('trim', explode("\n", trim($m[1]))));
        $items = [];

        foreach ($lines as $line) {
            // Strip leading bullet
            $line = trim(preg_replace('/^[-•]\s*/', '', $line));
            if (empty($line)) {
                continue;
            }

            $quantity = 1;
            $name = $line;

            // Try "x2" pattern first
            if (preg_match('/^(.+?)\s*x(\d+)\s*$/u', $line, $pm)) {
                $name = trim($pm[1]);
                $quantity = (int) $pm[2];
            }
            // Try "5 ตัว/เพจ/ชุด/รายการ" pattern
            elseif (preg_match('/^(.+?)\s+(\d+)\s*(?:ตัว|เพจ|ชุด|รายการ)\s*$/u', $line, $pm)) {
                $name = trim($pm[1]);
                $quantity = (int) $pm[2];
            }

            // Category detection
            $category = 'nolimit';
            if (mb_stripos($name, 'เพจ') !== false || mb_stripos($name, 'page') !== false) {
                $category = 'page';
            }

            $items[] = [
                'name' => $name,
                'quantity' => $quantity,
                'category' => $category,
            ];
        }

        return $items;
    }

    protected function extractBank(string $content): ?string
    {
        $banks = [
            'กสิกร' => 'กสิกรไทย (KBANK)',
            'KBANK' => 'กสิกรไทย (KBANK)',
            'K PLUS' => 'กสิกรไทย (KBANK)',
            'ไทยพาณิชย์' => 'ไทยพาณิชย์ (SCB)',
            'SCB' => 'ไทยพาณิชย์ (SCB)',
            'กรุงเทพ' => 'กรุงเทพ (BBL)',
            'BBL' => 'กรุงเทพ (BBL)',
            'กรุงไทย' => 'กรุงไทย (KTB)',
            'KTB' => 'กรุงไทย (KTB)',
            'กรุงศรี' => 'กรุงศรี (BAY)',
            'BAY' => 'กรุงศรี (BAY)',
            'ทหารไทยธนชาต' => 'ทหารไทยธนชาต (ttb)',
            'ttb' => 'ทหารไทยธนชาต (ttb)',
            'TMB' => 'ทหารไทยธนชาต (ttb)',
            'ออมสิน' => 'ออมสิน (GSB)',
            'GSB' => 'ออมสิน (GSB)',
            'PromptPay' => 'PromptPay',
            'พร้อมเพย์' => 'PromptPay',
        ];

        foreach ($banks as $keyword => $label) {
            if (mb_stripos($content, $keyword) !== false) {
                return $label;
            }
        }

        return null;
    }

    protected function processDryRun($query): void
    {
        $this->newLine();

        $existingOrderMessageIds = Order::whereNotNull('message_id')->pluck('message_id');
        $preview = [];

        $query->with(['conversation:id,bot_id,customer_profile_id'])
            ->chunkById(100, function ($messages) use ($existingOrderMessageIds, &$preview) {
                foreach ($messages as $message) {
                    if ($existingOrderMessageIds->contains($message->id)) {
                        $this->skipped++;

                        continue;
                    }

                    $content = $message->content;
                    $clean = str_replace('**', '', $content);
                    $rawAmount = $this->extractAmount($clean);
                    $amount = $rawAmount ? (float) str_replace([',', ' '], '', $rawAmount) : 0;
                    $productLines = $this->extractProductLines($content);
                    $bank = $this->extractBank($content);

                    $productSummary = ! empty($productLines)
                        ? implode(', ', array_map(fn ($p) => "{$p['name']} x{$p['quantity']}", $productLines))
                        : '(no products detected)';

                    $preview[] = [
                        $message->id,
                        $message->created_at->format('Y-m-d'),
                        number_format($amount, 2),
                        mb_strimwidth($productSummary, 0, 50, '...'),
                        count($productLines),
                        $bank ?? '-',
                    ];

                    $this->totalRevenue += $amount;
                    $this->created++;
                    $this->itemsCreated += max(count($productLines), 1);
                }
            });

        // Show first 20 rows as preview
        if (! empty($preview)) {
            $this->info('Preview (first 20):');
            $this->table(
                ['Msg ID', 'Date', 'Amount', 'Products', 'Items', 'Bank'],
                array_slice($preview, 0, 20)
            );
        }

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Would create orders', $this->created],
            ['Would create items', $this->itemsCreated],
            ['Would skip (existing)', $this->skipped],
            ['Total revenue', number_format($this->totalRevenue, 2) . ' ฿'],
        ]);
    }

    protected function logSummary(): void
    {
        $duration = $this->startTime->diffInSeconds(now());

        $summary = [
            ['Total messages', $this->totalMessages],
            ['Orders created', $this->created],
            ['Items created', $this->itemsCreated],
            ['Skipped (existing)', $this->skipped],
            ['Errors', $this->errors],
            ['Total revenue', number_format($this->totalRevenue, 2) . ' ฿'],
            ['Duration', "{$duration} seconds"],
        ];

        Log::info('BackfillOrders completed', [
            'total_messages' => $this->totalMessages,
            'created' => $this->created,
            'items_created' => $this->itemsCreated,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'total_revenue' => $this->totalRevenue,
            'duration_seconds' => $duration,
        ]);

        $this->info('Summary:');
        $this->table(['Metric', 'Value'], $summary);
    }
}
