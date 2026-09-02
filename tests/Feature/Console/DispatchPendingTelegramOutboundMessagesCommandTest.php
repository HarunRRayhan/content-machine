<?php

namespace Tests\Feature\Console;

use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramOutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchPendingTelegramOutboundMessagesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_only_due_pending_messages(): void
    {
        Queue::fake();
        $due = TelegramOutboundMessage::factory()->create();
        TelegramOutboundMessage::factory()->create(['next_attempt_at' => now()->addMinute()]);
        TelegramOutboundMessage::factory()->create(['status' => TelegramOutboundMessage::SENT]);
        TelegramOutboundMessage::factory()->create(['status' => TelegramOutboundMessage::DISCARDED]);

        $this->artisan('telegram:dispatch-pending-outbound-messages')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 1 pending Telegram outbound message(s).');

        Queue::assertPushed(SendTelegramOutboundMessageJob::class, fn (SendTelegramOutboundMessageJob $job): bool => $job->telegramOutboundMessageId === $due->id);
        $this->assertNotNull($due->refresh()->dispatch_lease_id);
        $this->assertNotNull($due->dispatch_claimed_at);

        $this->artisan('telegram:dispatch-pending-outbound-messages')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 0 pending Telegram outbound message(s).');
    }

    public function test_failed_messages_are_reopened_only_with_the_explicit_option(): void
    {
        Queue::fake();
        $failed = TelegramOutboundMessage::factory()->create([
            'status' => TelegramOutboundMessage::FAILED,
            'failed_at' => now(),
            'last_error' => 'failed',
        ]);

        $this->artisan('telegram:dispatch-pending-outbound-messages')
            ->assertSuccessful();
        $this->assertSame(TelegramOutboundMessage::FAILED, $failed->refresh()->status);
        Queue::assertNothingPushed();

        $this->artisan('telegram:dispatch-pending-outbound-messages', ['--retry-failed' => true])
            ->assertSuccessful();
        $this->assertSame(TelegramOutboundMessage::PENDING, $failed->refresh()->status);
        $this->assertNotNull($failed->dispatch_lease_id);
        Queue::assertPushed(SendTelegramOutboundMessageJob::class);
    }
}
