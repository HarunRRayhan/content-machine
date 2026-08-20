<?php

namespace Tests\Unit\Support\Telegram;

use App\Support\Telegram\HttpTelegramClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpTelegramClientTest extends TestCase
{
    public function test_a_successful_getme_returns_the_username()
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['id' => 1, 'is_bot' => true, 'username' => 'harun_capture_bot'],
        ])]);

        $result = (new HttpTelegramClient)->getMe('123456:test-token');

        $this->assertTrue($result->successful);
        $this->assertSame('harun_capture_bot', $result->username);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bot123456:test-token/getMe');
    }

    public function test_a_401_is_reported_as_an_invalid_token()
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);

        $result = (new HttpTelegramClient)->getMe('bad-token');

        $this->assertFalse($result->successful);
        $this->assertSame('Telegram rejected this token as invalid.', $result->error);
    }

    public function test_an_ok_false_response_surfaces_telegrams_description()
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

        $result = (new HttpTelegramClient)->getMe('123:token');

        $this->assertFalse($result->successful);
        $this->assertSame('Bad Request: chat not found', $result->error);
    }

    public function test_a_connection_failure_is_reported_without_leaking_the_exception()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $result = (new HttpTelegramClient)->getMe('123:token');

        $this->assertFalse($result->successful);
        $this->assertSame('Could not reach Telegram. Check your network and try again.', $result->error);
    }
}
