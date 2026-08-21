<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\LinkTelegramAccountAction;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LinkTelegramAccountActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_code_creates_the_link_and_consumes_the_code()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        $linkCode = TelegramLinkCode::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'code' => 'ABC12345']);

        $link = (new LinkTelegramAccountAction)->handle($config, 'abc12345', 999, 'someone');

        $this->assertSame($user->id, $link->user_id);
        $this->assertSame(999, $link->telegram_user_id);
        $this->assertSame('someone', $link->telegram_username);
        $this->assertNotNull($linkCode->fresh()->consumed_at);
    }

    public function test_an_unrecognized_code_throws()
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        $this->expectException(RuntimeException::class);

        (new LinkTelegramAccountAction)->handle($config, 'NOPE0000', 999, null);
    }

    public function test_an_expired_code_throws_and_is_not_consumed()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        $linkCode = TelegramLinkCode::factory()->expired()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'code' => 'EXPIRED1']);

        try {
            (new LinkTelegramAccountAction)->handle($config, 'EXPIRED1', 999, null);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }

        $this->assertSame(0, TelegramBotLink::count());
    }

    public function test_an_already_consumed_code_throws()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramLinkCode::factory()->consumed()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'code' => 'USED0000']);

        $this->expectException(RuntimeException::class);

        (new LinkTelegramAccountAction)->handle($config, 'USED0000', 999, null);
    }

    public function test_a_telegram_account_already_linked_to_someone_else_is_rejected()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $existingUser = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $existingUser->id, 'telegram_user_id' => 999]);

        $newUser = User::factory()->create();
        $linkCode = TelegramLinkCode::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $newUser->id, 'code' => 'NEWCODE1']);

        try {
            (new LinkTelegramAccountAction)->handle($config, 'NEWCODE1', 999, null);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already linked', $e->getMessage());
        }

        $this->assertNull($linkCode->fresh()->consumed_at);
    }

    public function test_relinking_the_same_user_updates_the_existing_link_instead_of_duplicating()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 111, 'telegram_username' => 'old_handle']);
        $linkCode = TelegramLinkCode::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'code' => 'RELINK01']);

        (new LinkTelegramAccountAction)->handle($config, 'RELINK01', 222, 'new_handle');

        $link = TelegramBotLink::sole();
        $this->assertSame(222, $link->telegram_user_id);
        $this->assertSame('new_handle', $link->telegram_username);
    }
}
