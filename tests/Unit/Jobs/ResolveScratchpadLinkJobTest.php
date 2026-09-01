<?php

namespace Tests\Unit\Jobs;

use App\Actions\Scratchpad\ResolveScratchpadLinkAction;
use App\Jobs\GenerateTelegramPostJob;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Jobs\SummarizeCaptureJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use App\Support\LinkResolution\LinkResolverContract;
use App\Support\LinkResolution\ResolvedLink;
use App\Support\Telegram\TelegramClientContract;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class ResolveScratchpadLinkJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_resolves_the_entry_via_the_action()
    {
        Queue::fake();

        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => Workspace::factory(),
            'kind' => 'link',
            'body' => 'https://example.com/post',
            'meta' => ['url' => 'https://example.com/post'],
        ]);

        $resolver = new class implements LinkResolverContract
        {
            public function resolve(string $url): ResolvedLink
            {
                return new ResolvedLink(kind: 'webpage', resolvedVia: 'page metadata (og tags)', title: 'Resolved Title');
            }
        };

        (new ResolveScratchpadLinkJob($entry))->handle(new ResolveScratchpadLinkAction($resolver));
        $entry->refresh();

        $this->assertSame('Resolved Title', $entry->title);
        $this->assertSame('page metadata (og tags)', $entry->meta['resolved_via']);
    }

    public function test_a_successful_resolution_dispatches_the_summarizer()
    {
        Queue::fake();

        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => Workspace::factory(),
            'kind' => 'link',
            'body' => 'https://example.com/post',
            'meta' => ['url' => 'https://example.com/post'],
        ]);

        $resolver = new class implements LinkResolverContract
        {
            public function resolve(string $url): ResolvedLink
            {
                return new ResolvedLink(kind: 'webpage', resolvedVia: 'page metadata (og tags)', title: 'Resolved Title');
            }
        };

        (new ResolveScratchpadLinkJob($entry))->handle(new ResolveScratchpadLinkAction($resolver));

        Queue::assertPushed(SummarizeCaptureJob::class, fn (SummarizeCaptureJob $job) => $job->entry->is($entry));
    }

    public function test_a_successful_resolution_dispatches_generation_for_a_telegram_post_request(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'link',
            'body' => 'https://example.com/post',
            'meta' => ['url' => 'https://example.com/post'],
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'state' => TelegramPostRequest::GENERATING,
        ]);
        $resolver = new class implements LinkResolverContract
        {
            public function resolve(string $url): ResolvedLink
            {
                return new ResolvedLink(kind: 'webpage', resolvedVia: 'page metadata', title: 'Resolved Title');
            }
        };

        (new ResolveScratchpadLinkJob($entry))->handle(new ResolveScratchpadLinkAction($resolver));

        Queue::assertPushed(GenerateTelegramPostJob::class, fn (GenerateTelegramPostJob $job): bool => $job->telegramPostRequestId === $request->id);
    }

    public function test_an_unresolved_link_does_not_dispatch_the_summarizer()
    {
        Queue::fake();

        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => Workspace::factory(),
            'kind' => 'link',
            'body' => 'https://example.com/post',
            'meta' => ['url' => 'https://example.com/post'],
        ]);

        $resolver = new class implements LinkResolverContract
        {
            public function resolve(string $url): ResolvedLink
            {
                return ResolvedLink::unresolved('metadata only (yt-dlp unavailable)');
            }
        };

        (new ResolveScratchpadLinkJob($entry))->handle(new ResolveScratchpadLinkAction($resolver));

        Queue::assertNotPushed(SummarizeCaptureJob::class);
    }

    public function test_an_unresolved_link_fails_the_waiting_telegram_post_request(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'link',
            'body' => 'https://example.com/post',
            'meta' => ['url' => 'https://example.com/post'],
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::GENERATING,
        ]);
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);

        $resolver = new class implements LinkResolverContract
        {
            public function resolve(string $url): ResolvedLink
            {
                return ResolvedLink::unresolved('page could not be read');
            }
        };

        (new ResolveScratchpadLinkJob($entry))->handle(new ResolveScratchpadLinkAction($resolver));

        $this->assertSame(TelegramPostRequest::FAILED, $request->refresh()->state);
        $this->assertStringContainsString('could not resolve', (string) $request->error_message);
        $this->assertStringContainsString('could not resolve', $client->sentMessages[0]['text']);
    }

    public function test_failed_marks_the_entry_as_unresolved_without_losing_the_url()
    {
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => Workspace::factory(),
            'kind' => 'link',
            'body' => 'https://example.com/post',
            'meta' => ['url' => 'https://example.com/post'],
        ]);

        (new ResolveScratchpadLinkJob($entry))->failed(new Exception('yt-dlp binary missing'));
        $entry->refresh();

        $this->assertSame('https://example.com/post', $entry->meta['url']);
        $this->assertSame('metadata only (resolution failed)', $entry->meta['resolved_via']);
        $this->assertSame('unresolved', $entry->meta['resolved_kind']);
    }

    public function test_failed_marks_link_post_requests_as_failed(): void
    {
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'link',
            'meta' => ['url' => 'https://example.com/post'],
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::GENERATING,
        ]);
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);

        (new ResolveScratchpadLinkJob($entry))->failed(new Exception('resolver failed'));

        $this->assertSame(TelegramPostRequest::FAILED, $request->refresh()->state);
        $this->assertStringContainsString('could not resolve', $client->sentMessages[0]['text']);
    }
}
