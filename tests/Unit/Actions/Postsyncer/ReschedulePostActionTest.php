<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\ReschedulePostAction;
use App\Data\Postsyncer\ReschedulePostData;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReschedulePostActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_the_existing_post_and_checkpoints_the_new_schedule(): void
    {
        $now = CarbonImmutable::now('Asia/Dhaka')->setSecond(0);
        $source = $now->addMinutes(10);
        $target = $now->addMinutes(39);
        $payload = $this->payload($source);
        $remote = $this->remote($source);

        Http::fake(function ($request) use ($remote, $source, $target): mixed {
            static $getCount = 0;

            if ($request->method() === 'PUT') {
                return Http::response([
                    ...$remote,
                    'scheduled_at' => $target->format('Y-m-d H:i'),
                ], 200);
            }

            $getCount++;

            return Http::response([
                ...$remote,
                'scheduled_at' => $getCount > 1
                    ? $target->format('Y-m-d H:i')
                    : $source->format('Y-m-d H:i'),
            ], 200);
        });

        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'publish_enabled' => true,
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => []],
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
            ],
        ]);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'publish_state' => 'succeeded',
            'postsyncer' => [
                'groups' => [[
                    'post_id' => '42',
                    'status' => 'SCHEDULED',
                    'scheduled_at' => $source->format('Y-m-d H:i'),
                    'platforms' => ['facebook'],
                    'language' => 'bangla',
                ]],
            ],
            'publish_progress' => [
                'state' => 'succeeded',
                'operation_id' => 'publish-operation',
                'completed_groups' => [[
                    'index' => 0,
                    'group_key' => 'group-key',
                    'post_id' => '42',
                    'status' => 'SCHEDULED',
                    'scheduled_at' => $source->format('Y-m-d H:i'),
                    'platforms' => ['facebook'],
                    'language' => 'bangla',
                    'expected_payload' => $payload,
                ]],
            ],
        ]);

        $action = app(ReschedulePostAction::class);
        $action->handle(
            $post,
            $workspace,
            new ReschedulePostData($target),
        );

        $post->refresh();

        $this->assertSame('scheduled', $post->status);
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame($target->format('Y-m-d H:i'), $post->postsyncer['groups'][0]['scheduled_at']);
        $this->assertSame('succeeded', $post->publish_progress['reschedule']['state']);
        $this->assertSame($target->toIso8601String(), $post->publish_progress['reschedule']['target_when']);
        $this->assertSame(
            $target->format('H:i'),
            $post->publish_progress['reschedule']['groups'][0]['payload']['schedule_for']['time'],
        );

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://postsyncer.com/api/v1/posts/42'
            && $request['schedule_for']['time'] === $target->format('H:i'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CarbonImmutable $when): array
    {
        return [
            'workspace_id' => 15211,
            'content' => [
                ['text' => 'Hello', 'media' => [915]],
                [
                    'text' => 'https://example.com/source',
                    'is_first_comment' => true,
                    'first_comment_delay' => 1,
                ],
            ],
            'accounts' => [[
                'id' => 100,
                'settings' => ['post_type' => 'POST', 'caption' => 'Hello'],
            ]],
            'schedule_type' => 'schedule',
            'schedule_for' => [
                'date' => $when->format('Y-m-d'),
                'time' => $when->format('H:i'),
                'timezone' => 'Asia/Dhaka',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remote(CarbonImmutable $when): array
    {
        return [
            'id' => 42,
            'workspace_id' => 15211,
            'content' => [
                ['text' => 'Hello', 'media' => [['id' => 915]]],
                [
                    'text' => 'https://example.com/source',
                    'is_first_comment' => true,
                    'first_comment_delay' => 1,
                ],
            ],
            'platforms' => [[
                'platform' => 'facebook',
                'account_id' => 100,
                // PostSyncer omits Facebook's caption after canonicalizing it
                // into the content item returned by GET /posts/{id}.
                'settings' => ['post_type' => 'POST'],
            ]],
            'status' => 'SCHEDULED',
            'scheduled_at' => $when->format('Y-m-d H:i'),
        ];
    }
}
