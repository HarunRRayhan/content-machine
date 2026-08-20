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

    public function test_set_webhook_sends_the_url_and_secret_token()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $result = (new HttpTelegramClient)->setWebhook('123:token', 'https://cm.harun.dev/telegram/webhook/abc', 'the-secret');

        $this->assertTrue($result->successful);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123:token/setWebhook')
            && $request['url'] === 'https://cm.harun.dev/telegram/webhook/abc'
            && $request['secret_token'] === 'the-secret');
    }

    public function test_set_webhook_reports_telegrams_rejection()
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Bad webhook: HTTPS url must be provided'], 400)]);

        $result = (new HttpTelegramClient)->setWebhook('123:token', 'http://insecure', 'secret');

        $this->assertFalse($result->successful);
        $this->assertSame('Bad webhook: HTTPS url must be provided', $result->error);
    }

    public function test_delete_webhook_reports_success()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $result = (new HttpTelegramClient)->deleteWebhook('123:token');

        $this->assertTrue($result->successful);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123:token/deleteWebhook'));
    }

    public function test_send_message_posts_the_chat_id_and_text()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $result = (new HttpTelegramClient)->sendMessage('123:token', 987654, 'Captured.');

        $this->assertTrue($result->successful);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123:token/sendMessage')
            && $request['chat_id'] === 987654
            && $request['text'] === 'Captured.');
    }

    public function test_a_connection_failure_on_send_message_is_reported_without_leaking_the_exception()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $result = (new HttpTelegramClient)->sendMessage('123:token', 1, 'hi');

        $this->assertFalse($result->successful);
        $this->assertSame('Could not reach Telegram to send the reply.', $result->error);
    }

    public function test_download_file_fetches_metadata_then_content()
    {
        Http::fake([
            'api.telegram.org/bot123:token/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_id' => 'f1', 'file_path' => 'voice/file_0.oga'],
            ]),
            'api.telegram.org/file/bot123:token/*' => Http::response('raw-file-bytes-here'),
        ]);

        $result = (new HttpTelegramClient)->downloadFile('123:token', 'f1');

        $this->assertTrue($result->successful);
        $this->assertSame('raw-file-bytes-here', $result->contents);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bot123:token/getFile?file_id=f1');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/file/bot123:token/voice/file_0.oga');
    }

    public function test_download_file_reports_telegrams_getfile_rejection()
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'file is too big'], 400)]);

        $result = (new HttpTelegramClient)->downloadFile('123:token', 'f1');

        $this->assertFalse($result->successful);
        $this->assertSame('file is too big', $result->error);
    }

    public function test_download_file_fails_when_telegram_omits_a_file_path()
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['file_id' => 'f1']])]);

        $result = (new HttpTelegramClient)->downloadFile('123:token', 'f1');

        $this->assertFalse($result->successful);
        $this->assertSame('Telegram did not return a path for that file.', $result->error);
    }

    public function test_download_file_fails_when_the_content_fetch_itself_fails()
    {
        Http::fake([
            'api.telegram.org/bot123:token/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg']]),
            'api.telegram.org/file/*' => Http::response('', 404),
        ]);

        $result = (new HttpTelegramClient)->downloadFile('123:token', 'f1');

        $this->assertFalse($result->successful);
        $this->assertSame('Telegram rejected the file download.', $result->error);
    }

    public function test_a_connection_failure_on_download_file_is_reported_without_leaking_the_exception()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $result = (new HttpTelegramClient)->downloadFile('123:token', 'f1');

        $this->assertFalse($result->successful);
        $this->assertSame('Could not reach Telegram to look up the file.', $result->error);
    }
}
