<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\CancelTelegramPostRequestAction;
use App\Models\TelegramPostRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelTelegramPostRequestActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_cancels_an_active_request_without_deleting_the_record(): void
    {
        $request = TelegramPostRequest::factory()->create([
            'state' => TelegramPostRequest::AWAITING_INPUT,
        ]);

        (new CancelTelegramPostRequestAction)->handle($request);

        $this->assertSame(TelegramPostRequest::CANCELLED, $request->refresh()->state);
        $this->assertNotNull($request->cancelled_at);
        $this->assertDatabaseHas('telegram_post_requests', ['id' => $request->id]);
    }
}
