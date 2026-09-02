<?php

namespace Tests\Feature\Console;

use App\Models\TelegramOutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileTelegramOutboundMessagesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_explicit_verification_and_confirmation(): void
    {
        $message = TelegramOutboundMessage::factory()->create([
            'status' => TelegramOutboundMessage::UNCERTAIN,
        ]);

        $this->artisan('telegram:reconcile-outbound', [
            '--id' => [$message->id],
            '--outcome' => 'retry',
        ])
            ->assertFailed()
            ->expectsOutput('Use --telegram-verified=not-delivered for --outcome=retry.');

        $this->assertSame(TelegramOutboundMessage::UNCERTAIN, $message->refresh()->status);
    }

    public function test_it_marks_selected_rows_as_discarded(): void
    {
        $message = TelegramOutboundMessage::factory()->create([
            'status' => TelegramOutboundMessage::UNCERTAIN,
            'failed_at' => now(),
        ]);

        $this->artisan('telegram:reconcile-outbound', [
            '--id' => [$message->id],
            '--outcome' => 'discarded',
            '--telegram-verified' => 'not-delivered',
            '--reason' => 'Checked the chat manually.',
            '--confirm' => true,
        ])
            ->assertSuccessful()
            ->expectsOutput('Reconciled 1 Telegram outbound message(s) as discarded.');

        $message->refresh();
        $this->assertSame(TelegramOutboundMessage::DISCARDED, $message->status);
        $this->assertSame('Checked the chat manually.', $message->last_error);
        $this->assertNotNull($message->discarded_at);
        $this->assertNull($message->failed_at);
    }

    public function test_it_reopens_only_selected_verified_rows_for_retry(): void
    {
        $message = TelegramOutboundMessage::factory()->create([
            'status' => TelegramOutboundMessage::UNCERTAIN,
            'failed_at' => now(),
            'next_chunk' => 2,
            'attempts' => 3,
        ]);

        $this->artisan('telegram:reconcile-outbound', [
            '--id' => [$message->id],
            '--outcome' => 'retry',
            '--telegram-verified' => 'not-delivered',
            '--confirm' => true,
        ])->assertSuccessful();

        $message->refresh();
        $this->assertSame(TelegramOutboundMessage::PENDING, $message->status);
        $this->assertSame(0, $message->next_chunk);
        $this->assertSame(0, $message->attempts);
        $this->assertNull($message->failed_at);
    }
}
