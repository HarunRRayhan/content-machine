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

    public function test_an_interrupted_send_is_marked_uncertain_and_never_requeued_implicitly(): void
    {
        Queue::fake();
        $message = TelegramOutboundMessage::factory()->create([
            'status' => TelegramOutboundMessage::SENDING,
            'dispatch_claimed_at' => now()->subSeconds(SendTelegramOutboundMessageJob::DISPATCH_LEASE_SECONDS + 1),
            'dispatch_lease_id' => '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22',
        ]);

        $this->artisan('telegram:dispatch-pending-outbound-messages')
            ->assertSuccessful();

        $this->assertSame(TelegramOutboundMessage::UNCERTAIN, $message->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_uncertain_messages_require_an_explicit_retry_option(): void
    {
        Queue::fake();
        $message = TelegramOutboundMessage::factory()->create([
            'status' => TelegramOutboundMessage::UNCERTAIN,
            'failed_at' => now(),
            'last_error' => 'uncertain',
        ]);

        $this->artisan('telegram:dispatch-pending-outbound-messages')
            ->assertSuccessful();
        $this->assertSame(TelegramOutboundMessage::UNCERTAIN, $message->refresh()->status);
        Queue::assertNothingPushed();

        $this->artisan('telegram:dispatch-pending-outbound-messages', ['--retry-uncertain' => true])
            ->assertSuccessful();
        $this->assertSame(TelegramOutboundMessage::PENDING, $message->refresh()->status);
        Queue::assertPushed(SendTelegramOutboundMessageJob::class);
    }

    public function test_a_stale_send_is_not_retried_in_the_same_uncertain_recovery_run(): void
    {
        Queue::fake();
        $stale = TelegramOutboundMessage::factory()->create([
            'status' => TelegramOutboundMessage::SENDING,
            'dispatch_claimed_at' => now()->subSeconds(SendTelegramOutboundMessageJob::DISPATCH_LEASE_SECONDS + 1),
            'dispatch_lease_id' => '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22',
        ]);

        $this->artisan('telegram:dispatch-pending-outbound-messages', [
            '--retry-uncertain' => true,
        ])
            ->assertSuccessful()
            ->expectsOutput('Dispatched 0 pending Telegram outbound message(s).');

        $this->assertSame(TelegramOutboundMessage::UNCERTAIN, $stale->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_retry_does_not_reopen_a_message_with_a_live_dispatch_lease(): void
    {
        Queue::fake();
        $message = TelegramOutboundMessage::factory()->create([
            'status' => TelegramOutboundMessage::UNCERTAIN,
            'failed_at' => now(),
            'dispatch_claimed_at' => now(),
            'dispatch_lease_id' => '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22',
        ]);

        $this->artisan('telegram:dispatch-pending-outbound-messages', [
            '--retry-uncertain' => true,
        ])
            ->assertSuccessful()
            ->expectsOutput('Dispatched 0 pending Telegram outbound message(s).');

        $this->assertSame(TelegramOutboundMessage::UNCERTAIN, $message->refresh()->status);
        Queue::assertNothingPushed();
    }
}
