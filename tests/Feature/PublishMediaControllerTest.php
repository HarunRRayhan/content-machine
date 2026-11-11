<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PublishMediaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_url_streams_post_attachment_without_auth(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'posts/test.png',
            'mime' => 'image/png',
            'original_filename' => 'test.png',
        ]);
        Storage::disk('scratchpad')->put('posts/test.png', 'png-bytes');

        Attachment::factory()->for($post, 'attachable')->for($media)->create();

        $url = URL::temporarySignedRoute(
            'publish-media.post',
            now()->addHour(),
            ['post' => $post->id, 'mediaAsset' => $media->id],
        );

        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_signed_url_validates_https_scheme_from_reverse_proxy(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'posts/proxied.png',
            'mime' => 'image/png',
            'original_filename' => 'proxied.png',
        ]);
        Storage::disk('scratchpad')->put('posts/proxied.png', 'png-bytes');

        Attachment::factory()->for($post, 'attachable')->for($media)->create();

        URL::forceRootUrl('https://cm.harun.dev');
        URL::forceScheme('https');
        $url = URL::temporarySignedRoute(
            'publish-media.post',
            now()->addHour(),
            ['post' => $post->id, 'mediaAsset' => $media->id],
        );

        $response = $this->withServerVariables([
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
        ])->withHeaders([
            'Host' => 'cm.harun.dev',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'cm.harun.dev',
        ])->get($url);

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_unsigned_url_is_rejected(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $media = MediaAsset::factory()->for($workspace)->create();

        Attachment::factory()->for($post, 'attachable')->for($media)->create();

        $this->get(route('publish-media.post', ['post' => $post->id, 'mediaAsset' => $media->id]))
            ->assertForbidden();
    }

    /** @return array{0: Post, 1: string} a post with an attached image and a link that expired an hour ago */
    private function expiredLink(string $status = 'scheduled'): array
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create(['status' => $status]);
        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'posts/late.png',
            'mime' => 'image/png',
            'original_filename' => 'late.png',
        ]);
        Storage::disk('scratchpad')->put('posts/late.png', 'png-bytes');
        Attachment::factory()->for($post, 'attachable')->for($media)->create();

        $url = URL::temporarySignedRoute(
            'publish-media.post',
            now()->subHour(),
            ['post' => $post->id, 'mediaAsset' => $media->id],
        );

        return [$post, $url];
    }

    private function socialreinsGroup(Post $post, string $scheduledAt, string $state = SocialreinsGroup::CREATED): void
    {
        SocialreinsGroup::query()->create([
            'workspace_id' => $post->workspace_id, 'subject_type' => 'post', 'subject_id' => $post->id,
            'language' => 'english', 'sequence' => 1, 'idempotency_key' => "k{$post->id}", 'state' => $state,
            'request' => json_encode(['scheduled_at' => $scheduledAt]),
        ]);
    }

    public function test_expired_link_still_streams_for_a_scheduled_socialreins_post(): void
    {
        [$post, $url] = $this->expiredLink();
        $this->socialreinsGroup($post, now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'));

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_expired_link_streams_shortly_after_the_socialreins_scheduled_time(): void
    {
        [$post, $url] = $this->expiredLink();
        $this->socialreinsGroup($post, now()->subDays(6)->utc()->format('Y-m-d\TH:i:s\Z'));

        $this->get($url)->assertOk();
    }

    public function test_expired_link_is_rejected_long_after_the_socialreins_scheduled_time(): void
    {
        [$post, $url] = $this->expiredLink('posted');
        $this->socialreinsGroup($post, now()->subDays(8)->utc()->format('Y-m-d\TH:i:s\Z'));

        $this->get($url)->assertForbidden();
    }

    public function test_expired_link_is_rejected_without_a_live_socialreins_group(): void
    {
        [$post, $url] = $this->expiredLink();
        $this->get($url)->assertForbidden();

        $this->socialreinsGroup($post, now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'), SocialreinsGroup::REJECTED);
        $this->get($url)->assertForbidden();
    }

    public function test_expired_link_is_rejected_for_an_archived_post(): void
    {
        [$post, $url] = $this->expiredLink('archived');
        $this->socialreinsGroup($post, now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'));

        $this->get($url)->assertForbidden();
    }

    public function test_tampered_signature_is_rejected_even_for_a_scheduled_socialreins_post(): void
    {
        [$post, $url] = $this->expiredLink();
        $this->socialreinsGroup($post, now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'));

        $this->get(preg_replace('/signature=[0-9a-f]/', 'signature=x', $url))->assertForbidden();
        $this->get(preg_replace('/expires=\d+/', 'expires='.now()->addDay()->timestamp, $url))->assertForbidden();
    }
}
