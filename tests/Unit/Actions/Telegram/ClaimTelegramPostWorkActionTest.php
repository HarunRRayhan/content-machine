<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\ClaimTelegramPostWorkAction;
use App\Models\TelegramPostRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimTelegramPostWorkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renews_only_a_live_lease(): void
    {
        $request = TelegramPostRequest::factory()->create([
            'state' => TelegramPostRequest::GENERATING,
            'work_claimed_at' => now(),
            'work_lease_id' => '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22',
        ]);
        $action = new ClaimTelegramPostWorkAction;

        $this->travel(10)->seconds();
        $this->assertTrue($action->renew($request->id, (string) $request->work_lease_id));

        $this->travel(ClaimTelegramPostWorkAction::LEASE_SECONDS)->seconds();
        $this->assertFalse($action->renew($request->id, (string) $request->work_lease_id));
    }
}
