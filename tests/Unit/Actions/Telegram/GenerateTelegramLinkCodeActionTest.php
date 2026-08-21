<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\GenerateTelegramLinkCodeAction;
use App\Models\TelegramBotConfig;
use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateTelegramLinkCodeActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_short_lived_code_for_the_user()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();

        $code = (new GenerateTelegramLinkCodeAction)->handle($config, $user);

        $this->assertSame($config->id, $code->telegram_bot_config_id);
        $this->assertSame($user->id, $code->user_id);
        $this->assertNotEmpty($code->code);
        $this->assertNull($code->consumed_at);
        $this->assertTrue($code->expires_at->isFuture());
        $this->assertTrue($code->isUsable());
    }

    public function test_requesting_a_new_code_invalidates_the_users_earlier_unused_codes()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();

        $first = (new GenerateTelegramLinkCodeAction)->handle($config, $user);
        $second = (new GenerateTelegramLinkCodeAction)->handle($config, $user);

        $this->assertNotSame($first->code, $second->code);
        $this->assertSame(1, TelegramLinkCode::query()->where('user_id', $user->id)->count());
        $this->assertSame($second->id, TelegramLinkCode::sole()->id);
    }

    public function test_it_does_not_touch_another_users_codes()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        (new GenerateTelegramLinkCodeAction)->handle($config, $userA);
        (new GenerateTelegramLinkCodeAction)->handle($config, $userB);

        $this->assertSame(2, TelegramLinkCode::count());
    }
}
