<?php

namespace Tests\Unit\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Support\AiProviders\OpenAiTranscriptionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiTranscriptionClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_transcription_hits_the_default_base_url_with_the_right_model()
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'text' => 'ওয়েবহুক পাঠায় না, পোলিং করে।',
            'language' => 'bengali',
        ], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make(['api_key' => 'sk-openai-test']);

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertTrue($result->successful);
        $this->assertSame('ওয়েবহুক পাঠায় না, পোলিং করে।', $result->text);
        $this->assertSame('bengali', $result->language);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && $request->hasHeader('Authorization', 'Bearer sk-openai-test')
            && str_contains((string) $request->body(), 'whisper-1')
            && str_contains((string) $request->body(), 'verbose_json'));
    }

    public function test_a_custom_base_url_is_used_when_given()
    {
        Http::fake(['*' => Http::response(['text' => 'hello'], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make(['base_url' => 'https://proxy.example.com/openai/v1']);

        (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        Http::assertSent(fn ($request) => $request->url() === 'https://proxy.example.com/openai/v1/audio/transcriptions');
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

    public function test_a_missing_language_is_left_null()
    {
        Http::fake(['*' => Http::response(['text' => 'hello there'], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new OpenAiTranscriptionClient)->transcribe($credential, 'raw-audio-bytes', 'note.ogg', 'audio/ogg');

        $this->assertTrue($result->successful);
        $this->assertNull($result->language);
    }
}
