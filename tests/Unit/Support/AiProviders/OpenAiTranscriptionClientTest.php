<?php

namespace Tests\Unit\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Support\AiProviders\OpenAiTranscriptionClient;
use App\Support\LinkResolution\PublicUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiTranscriptionClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_gpt_4o_transcribe_is_tried_first_and_infers_bengali()
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'text' => 'ওয়েবহুক পাঠায় না, পোলিং করে।',
        ], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make(['api_key' => 'sk-openai-test']);

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertTrue($result->successful);
        $this->assertSame('ওয়েবহুক পাঠায় না, পোলিং করে।', $result->text);
        $this->assertSame('bengali', $result->language);
        $this->assertSame('gpt-4o-transcribe', $result->model);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && $request->hasHeader('Authorization', 'Bearer sk-openai-test')
            && str_contains((string) $request->body(), 'gpt-4o-transcribe')
            && ! str_contains((string) $request->body(), 'verbose_json'));
    }

    public function test_gpt_4o_transcribe_infers_english_without_bengali_script()
    {
        Http::fake(['*' => Http::response(['text' => 'hello there'], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertSame('english', $result->language);
        $this->assertSame('gpt-4o-transcribe', $result->model);
    }

    public function test_a_gpt_4o_transcribe_failure_falls_back_to_whisper_1()
    {
        Http::fake(fn ($request) => str_contains((string) $request->body(), 'gpt-4o-transcribe')
            ? Http::response(['error' => ['message' => 'model unavailable']], 503)
            : Http::response(['text' => 'whisper text', 'language' => 'bengali'], 200));

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertTrue($result->successful);
        $this->assertSame('whisper text', $result->text);
        $this->assertSame('bengali', $result->language);
        $this->assertSame('whisper-1', $result->model);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'whisper-1')
            && str_contains((string) $request->body(), 'verbose_json'));
    }

    public function test_empty_gpt_4o_transcribe_text_falls_back_to_whisper_1()
    {
        Http::fake(fn ($request) => str_contains((string) $request->body(), 'gpt-4o-transcribe')
            ? Http::response(['text' => ''], 200)
            : Http::response(['text' => 'whisper text', 'language' => 'english'], 200));

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertSame('whisper-1', $result->model);
    }

    public function test_when_both_models_fail_the_first_error_is_returned()
    {
        Http::fake(fn ($request) => str_contains((string) $request->body(), 'gpt-4o-transcribe')
            ? Http::response(['error' => ['message' => '4o is down']], 500)
            : Http::response(['error' => ['message' => 'whisper is down']], 500));

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertFalse($result->successful);
        $this->assertSame('4o is down', $result->error);
        Http::assertSentCount(2);
    }

    public function test_a_custom_base_url_is_used_when_given()
    {
        Http::fake(['*' => Http::response(['text' => 'hello'], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make(['base_url' => 'https://proxy.example.com/openai/v1']);

        (new OpenAiTranscriptionClient(new PublicUrlGuard(
            fn (string $host): array => [['ip' => '1.1.1.1']],
        )))->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        Http::assertSent(fn ($request) => $request->url() === 'https://proxy.example.com/openai/v1/audio/transcriptions');
    }

    public function test_a_custom_base_url_resolving_to_a_private_ip_is_rejected(): void
    {
        Http::fake();

        $credential = AiProviderCredential::factory()->make([
            'base_url' => 'https://internal.example/v1',
        ]);

        $result = (new OpenAiTranscriptionClient(new PublicUrlGuard(
            fn (string $host): array => [['ip' => '192.168.1.1']],
        )))->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertFalse($result->successful);
        $this->assertSame('Could not reach the transcription provider.', $result->error);
        Http::assertNothingSent();
    }

    public function test_a_provider_error_message_is_surfaced_when_present()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'invalid file format']], 400)]);

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertFalse($result->successful);
        $this->assertSame('invalid file format', $result->error);
    }

    public function test_a_status_with_no_error_message_gets_a_generic_message()
    {
        Http::fake(['*' => Http::response([], 500)]);

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertFalse($result->successful);
        $this->assertSame('The transcription provider returned an unexpected status (500).', $result->error);
    }

    public function test_empty_text_is_reported_as_a_failure()
    {
        Http::fake(['*' => Http::response(['text' => '   '], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertFalse($result->successful);
        $this->assertSame('The transcription provider returned no text.', $result->error);
    }

    public function test_a_connection_failure_is_reported_without_leaking_the_exception()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertFalse($result->successful);
        $this->assertSame('Could not reach the transcription provider.', $result->error);
    }

    public function test_a_missing_whisper_language_is_left_null()
    {
        Http::fake(fn ($request) => str_contains((string) $request->body(), 'gpt-4o-transcribe')
            ? Http::response([], 500)
            : Http::response(['text' => 'hello there'], 200));

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertTrue($result->successful);
        $this->assertSame('whisper-1', $result->model);
        $this->assertNull($result->language);
    }
}
