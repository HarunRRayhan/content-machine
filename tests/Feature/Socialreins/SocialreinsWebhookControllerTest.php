<?php

namespace Tests\Feature\Socialreins;

use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialreinsWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        SocialreinsConfig::write($this->workspace, ['webhook_secret' => 'whsec_test']);
        $this->post = Post::factory()->for($this->workspace)->create(['status' => 'scheduled', 'publish_state' => 'succeeded']);
        SocialreinsGroup::query()->create([
            'workspace_id' => $this->workspace->id, 'subject_type' => 'post', 'subject_id' => $this->post->id,
            'language' => 'english', 'sequence' => 1, 'idempotency_key' => 'k', 'state' => 'created', 'request' => '{}',
            'group_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'results' => ['bbbbbbbb-0000-4000-8000-000000000001' => ['platform' => 'facebook', 'status' => 'scheduled', 'error' => null, 'targets' => []]],
        ]);
    }

    private function body(string $status = 'published', string $group = 'aaaaaaaa-0000-4000-8000-000000000001'): string
    {
        return json_encode([
            'id' => 'cccccccc-0000-4000-8000-000000000001',
            'type' => "post.target.{$status}",
            'created_at' => '2026-11-07T10:00:00Z',
            'data' => [
                'post_id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'group_id' => $group, 'target_id' => 't1',
                'platform' => 'facebook', 'status' => $status, 'remote_url' => 'https://fb.test/1', 'error' => null,
            ],
        ]);
    }

    private function deliver(string $body, ?string $signature, ?int $workspace = null)
    {
        return $this->call('POST', '/socialreins/webhook/'.($workspace ?? $this->workspace->id), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SOCIALREINS_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_a_good_signature_updates_cm_publish_state(): void
    {
        $body = $this->body();

        $this->deliver($body, WebhookSignature::sign('whsec_test', time(), $body))->assertOk();

        $this->assertSame('posted', $this->post->fresh()->status);
        $group = SocialreinsGroup::query()->first();
        $this->assertSame('https://fb.test/1', $group->results['bbbbbbbb-0000-4000-8000-000000000001']['targets']['t1']['remote_url']);
    }

    public function test_a_failed_event_marks_the_publish_failed_and_the_same_event_twice_changes_nothing(): void
    {
        $body = $this->body('failed');
        $signature = WebhookSignature::sign('whsec_test', time(), $body);

        $this->deliver($body, $signature)->assertOk();
        $this->deliver($body, $signature)->assertOk();

        $post = $this->post->fresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('facebook', $post->publish_error);
    }

    public function test_a_bad_missing_or_stale_signature_is_refused_and_changes_nothing(): void
    {
        $body = $this->body();

        $this->deliver($body, WebhookSignature::sign('whsec_wrong', time(), $body))->assertUnauthorized();
        $this->deliver($body, null)->assertUnauthorized();
        $this->deliver($body, WebhookSignature::sign('whsec_test', time() - 3600, $body))->assertUnauthorized();
        $this->deliver($body.' ', WebhookSignature::sign('whsec_test', time(), $body))->assertUnauthorized();

        $this->assertSame('scheduled', $this->post->fresh()->status);
    }

    public function test_a_workspace_without_a_secret_does_not_listen(): void
    {
        $other = Workspace::factory()->create();

        $this->deliver('{}', WebhookSignature::sign('whsec_test', time(), '{}'), $other->id)->assertNotFound();
    }

    public function test_an_unknown_group_gets_a_retryable_answer_and_a_ping_is_acknowledged(): void
    {
        $body = $this->body('published', 'dddddddd-0000-4000-8000-000000000009');
        $this->deliver($body, WebhookSignature::sign('whsec_test', time(), $body))->assertNotFound();

        $ping = json_encode(['id' => 'x', 'type' => 'ping', 'data' => []]);
        $this->deliver($ping, WebhookSignature::sign('whsec_test', time(), $ping))->assertOk();
    }
}
