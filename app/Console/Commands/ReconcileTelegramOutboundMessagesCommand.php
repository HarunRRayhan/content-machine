<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramOutboundMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileTelegramOutboundMessagesCommand extends Command
{
    protected $signature = 'telegram:reconcile-outbound
        {--id=* : Outbound message id; repeat for each row to reconcile}
        {--outcome= : sent, discarded, or retry}
        {--telegram-verified= : delivered or not-delivered}
        {--reason= : Optional operator note recorded on the row}
        {--confirm : Confirm that Telegram was checked and this change is intentional}
        {--list : List active outbound rows without changing data}';

    protected $description = 'Explicitly reconcile Telegram outbound delivery rows';

    public function handle(): int
    {
        $ids = $this->messageIds();

        if ($this->option('list')) {
            return $this->listActiveMessages();
        }

        if ($ids === []) {
            $this->error('Pass one or more --id values, or use --list.');

            return self::FAILURE;
        }

        $outcome = (string) ($this->option('outcome') ?? '');
        if (! in_array($outcome, ['sent', 'discarded', 'retry'], true)) {
            $this->error('The --outcome option must be sent, discarded, or retry.');

            return self::FAILURE;
        }

        $verification = (string) ($this->option('telegram-verified') ?? '');
        $expectedVerification = $outcome === 'sent' ? 'delivered' : 'not-delivered';
        if ($verification !== $expectedVerification) {
            $this->error("Use --telegram-verified={$expectedVerification} for --outcome={$outcome}.");

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->error('Pass --confirm after checking the Telegram chat for every selected row.');

            return self::FAILURE;
        }

        $reason = trim((string) ($this->option('reason') ?? ''));
        if ($reason === '') {
            $reason = match ($outcome) {
                'sent' => 'Telegram delivery was verified manually.',
                'discarded' => 'Telegram delivery was verified absent; message discarded manually.',
                'retry' => 'Telegram delivery was verified absent; retry approved manually.',
            };
        }

        try {
            $changed = DB::transaction(function () use ($ids, $outcome, $reason): int {
                $messages = TelegramOutboundMessage::query()
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lock('FOR UPDATE')
                    ->get();

                if ($messages->count() !== count($ids)) {
                    throw new \RuntimeException('One or more selected Telegram outbound rows do not exist.');
                }

                $liveAt = now()->subSeconds(SendTelegramOutboundMessageJob::DISPATCH_LEASE_SECONDS);
                foreach ($messages as $message) {
                    if (! in_array($message->status, [
                        TelegramOutboundMessage::PENDING,
                        TelegramOutboundMessage::SENDING,
                        TelegramOutboundMessage::UNCERTAIN,
                    ], true)) {
                        throw new \RuntimeException(
                            "Telegram outbound row {$message->id} is already terminal ({$message->status}).",
                        );
                    }

                    if ($message->dispatch_lease_id !== null
                        && $message->dispatch_claimed_at !== null
                        && $message->dispatch_claimed_at->isAfter($liveAt)
                    ) {
                        throw new \RuntimeException(
                            "Telegram outbound row {$message->id} still has a live dispatch lease.",
                        );
                    }
                }

                foreach ($messages as $message) {
                    $attributes = [
                        'last_error' => $reason,
                        'next_attempt_at' => null,
                        'dispatch_claimed_at' => null,
                        'dispatch_lease_id' => null,
                        'updated_at' => now(),
                    ];

                    if ($outcome === 'sent') {
                        $attributes += [
                            'status' => TelegramOutboundMessage::SENT,
                            'next_chunk' => count($message->chunks),
                            'attempts' => 0,
                            'failed_at' => null,
                            'discarded_at' => null,
                            'sent_at' => now(),
                        ];
                    } elseif ($outcome === 'discarded') {
                        $attributes += [
                            'status' => TelegramOutboundMessage::DISCARDED,
                            'failed_at' => null,
                            'discarded_at' => now(),
                            'sent_at' => null,
                        ];
                    } else {
                        $attributes += [
                            'status' => TelegramOutboundMessage::PENDING,
                            'next_chunk' => 0,
                            'attempts' => 0,
                            'failed_at' => null,
                            'discarded_at' => null,
                            'sent_at' => null,
                        ];
                    }

                    $message->forceFill($attributes)->save();
                }

                return $messages->count();
            });
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Reconciled {$changed} Telegram outbound message(s) as {$outcome}.");

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function messageIds(): array
    {
        $ids = [];
        foreach ((array) $this->option('id') as $value) {
            foreach (preg_split('/\s*,\s*/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (! ctype_digit($part) || (int) $part < 1) {
                    $this->error("Invalid Telegram outbound message id [{$part}].");

                    return [];
                }

                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    private function listActiveMessages(): int
    {
        $messages = TelegramOutboundMessage::query()
            ->whereIn('status', [
                TelegramOutboundMessage::PENDING,
                TelegramOutboundMessage::SENDING,
                TelegramOutboundMessage::UNCERTAIN,
            ])
            ->orderBy('id')
            ->get(['id', 'status', 'webhook_generation', 'logical_key', 'updated_at']);

        if ($messages->isEmpty()) {
            $this->info('No active Telegram outbound messages require reconciliation.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'status', 'webhook_generation', 'logical_key', 'updated_at'],
            $messages->map(fn (TelegramOutboundMessage $message): array => [
                $message->id,
                $message->status,
                $message->webhook_generation,
                $message->logical_key,
                $message->updated_at?->toIso8601String(),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
