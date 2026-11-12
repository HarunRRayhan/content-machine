<?php

namespace Tests\Unit\Actions\Calendar;

use App\Actions\Calendar\ListCalendarEventsAction;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Calendar\CollectCalendarOccurrences;
use App\Support\Content\PublishGroups;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListCalendarEventsActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): ListCalendarEventsAction
    {
        return new ListCalendarEventsAction(new CollectCalendarOccurrences);
    }

    public function test_it_lists_a_scheduled_post_and_a_published_video_on_the_same_day(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        $post = Post::factory()->for($workspace)->create([
            'title' => 'Morning post',
            'human_id' => 'P-50',
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '1',
                        'scheduled_at' => '2026-08-26T09:00:00+06:00',
                        'language' => 'bangla',
                    ],
                    [
                        'post_id' => '2',
                        'scheduled_at' => '2026-08-26T09:00:00+06:00',
                        'language' => 'english',
                    ],
                ],
            ],
        ]);

        $video = Video::factory()->for($workspace)->create([
            'title' => 'Evening video',
            'human_id' => 'BV-50',
            'status' => 'posted',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '3',
                        'scheduled_at' => '2026-08-26T17:45:00+06:00',
                        'published_at' => '2026-08-26T17:46:00+06:00',
                    ],
                ],
            ],
        ]);

        $events = $this->action()->handle($workspace, 2026, 8);

        $this->assertCount(2, $events);
        $this->assertSame('P-50', $events[0]['human_id']);
        $this->assertSame('scheduled', $events[0]['state']);
        $this->assertSame('/posts/P-50', $events[0]['href']);
        $this->assertSame($post->id, $events[0]['id']);
        $this->assertSame('BV-50', $events[1]['human_id']);
        $this->assertSame('published', $events[1]['state']);
        $this->assertSame('/videos/BV-50', $events[1]['href']);
        $this->assertSame($video->id, $events[1]['id']);
    }

    public function test_it_shows_one_chip_per_post_no_matter_how_many_groups_or_retries(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        Post::factory()->for($workspace)->create([
            'title' => 'Claude Fable',
            'human_id' => 'P-71',
            'status' => 'posted',
            'postsyncer' => [
                'groups' => [
                    ['post_id' => '1', 'scheduled_at' => '2026-09-03T13:21:00+06:00', 'published_at' => '2026-09-03T13:21:00+06:00'],
                    ['post_id' => '2', 'scheduled_at' => '2026-09-03T14:24:00+06:00', 'published_at' => '2026-09-03T14:24:00+06:00'],
                    ['post_id' => '3', 'scheduled_at' => '2026-09-03T15:00:00+06:00', 'published_at' => '2026-09-03T15:00:00+06:00'],
                    ['post_id' => '4', 'scheduled_at' => '2026-09-03T15:11:00+06:00', 'published_at' => '2026-09-03T15:11:00+06:00'],
                    ['post_id' => '5', 'scheduled_at' => '2026-09-03T15:12:00+06:00', 'published_at' => '2026-09-03T15:12:00+06:00'],
                    ['post_id' => '6', 'scheduled_at' => '2026-09-03T15:38:00+06:00', 'published_at' => '2026-09-03T15:38:00+06:00'],
                ],
            ],
        ]);

        $events = $this->action()->handle($workspace, 2026, 9);

        $this->assertCount(1, $events);
        $this->assertSame('P-71', $events[0]['human_id']);
        $this->assertSame('published', $events[0]['state']);
        $this->assertSame('2026-09-03', $events[0]['date']);
        $this->assertSame('2026-09-03T15:38:00+06:00', $events[0]['at']);
    }

    public function test_it_collapses_scheduled_groups_at_different_times_into_one_chip(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        Post::factory()->for($workspace)->create([
            'title' => 'Drifted schedule',
            'human_id' => 'P-72',
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    ['post_id' => '1', 'scheduled_at' => '2026-09-04T09:23:00+06:00'],
                    ['post_id' => '2', 'scheduled_at' => '2026-09-04T10:15:00+06:00'],
                ],
            ],
        ]);

        $events = $this->action()->handle($workspace, 2026, 9);

        $this->assertCount(1, $events);
        $this->assertSame('P-72', $events[0]['human_id']);
        $this->assertSame('scheduled', $events[0]['state']);
        $this->assertSame('2026-09-04', $events[0]['date']);
    }

    public function test_published_groups_win_over_scheduled_groups_on_the_same_record(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        Post::factory()->for($workspace)->create([
            'title' => 'Partially live',
            'human_id' => 'P-73',
            'status' => 'posted',
            'postsyncer' => [
                'groups' => [
                    ['post_id' => '1', 'scheduled_at' => '2026-09-05T09:00:00+06:00'],
                    ['post_id' => '2', 'scheduled_at' => '2026-09-05T09:00:00+06:00', 'published_at' => '2026-09-05T09:05:00+06:00'],
                ],
            ],
        ]);

        $events = $this->action()->handle($workspace, 2026, 9);

        $this->assertCount(1, $events);
        $this->assertSame('P-73', $events[0]['human_id']);
        $this->assertSame('published', $events[0]['state']);
    }

    public function test_it_ignores_other_workspaces_and_records_without_dates(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        $other = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        Post::factory()->for($workspace)->create([
            'title' => 'Draft',
            'status' => 'draft',
        ]);

        Post::factory()->for($other)->create([
            'title' => 'Someone else',
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    ['scheduled_at' => '2026-08-26T09:00:00+06:00'],
                ],
            ],
        ]);

        $this->assertSame([], $this->action()->handle($workspace, 2026, 8));
    }

    public function test_each_event_carries_its_color_category(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        $groups = ['groups' => [['post_id' => '1', 'scheduled_at' => '2026-08-26T09:00:00+06:00']]];

        Post::factory()->for($workspace)->create(['human_id' => 'P-1', 'category' => 'default', 'postsyncer' => $groups]);
        Post::factory()->for($workspace)->create(['human_id' => 'P-2', 'category' => 'morning', 'postsyncer' => $groups]);
        Post::factory()->for($workspace)->create(['human_id' => 'P-3', 'category' => 'evening_carousel', 'postsyncer' => $groups]);
        Video::factory()->for($workspace)->create(['human_id' => 'BV-1', 'language' => 'bn', 'postsyncer' => $groups]);
        Video::factory()->for($workspace)->create(['human_id' => 'EV-1', 'language' => 'en', 'postsyncer' => $groups]);

        $categories = array_column($this->action()->handle($workspace, 2026, 8), 'category', 'human_id');

        $this->assertSame([
            'P-1' => 'post_default',
            'P-2' => 'post_morning',
            'P-3' => 'post_evening_carousel',
            'BV-1' => 'video_bn',
            'EV-1' => 'video_en',
        ], $categories);
    }

    public function test_it_lists_socialreins_content_once_per_post_or_video(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        $socialreins = function (Post|Video $subject, array $results) use ($workspace): void {
            SocialreinsGroup::query()->create([
                'workspace_id' => $workspace->id, 'subject_type' => $subject instanceof Video ? 'video' : 'post', 'subject_id' => $subject->id,
                'language' => 'bangla', 'sequence' => 1, 'idempotency_key' => 'k'.$subject->human_id, 'state' => 'created',
                'request' => json_encode(['scheduled_at' => '2026-08-26T03:00:00Z']),
                'group_id' => null, 'expected_targets' => count($results), 'results' => $results,
            ]);
        };
        $result = fn (string $platform, string $status = 'scheduled', array $own = []): array => ['platform' => $platform, 'status' => $status, 'error' => null, 'at' => 0, 'targets' => [], ...$own];

        // Whole on SocialReins, no PostSyncer data at all.
        $only = Post::factory()->for($workspace)->create(['title' => 'Only', 'human_id' => 'P-80', 'status' => 'scheduled', 'postsyncer' => null]);
        $socialreins($only, ['p1' => $result('linkedin'), 'p2' => $result('threads')]);

        // A split: PostSyncer half at 09:00, SocialReins half rescheduled to 10:00 the same day.
        $split = Post::factory()->for($workspace)->create([
            'title' => 'Split', 'human_id' => 'P-81', 'status' => 'scheduled',
            'postsyncer' => ['groups' => [['post_id' => '42', 'scheduled_at' => '2026-08-27T09:00:00+06:00', 'platforms' => ['facebook']]]],
        ]);
        $socialreins($split, ['p3' => $result('linkedin', 'scheduled', ['scheduled_at' => '2026-08-27T04:00:00Z'])]);

        // Published through SocialReins.
        $video = Video::factory()->for($workspace)->create(['title' => 'Live', 'human_id' => 'BV-80', 'status' => 'posted', 'postsyncer' => null]);
        $socialreins($video, ['p4' => $result('youtube', 'published')]);

        // Taken down from SocialReins: nothing is scheduled any more.
        $gone = Post::factory()->for($workspace)->create(['title' => 'Gone', 'human_id' => 'P-82', 'status' => 'ready', 'postsyncer' => null]);
        $socialreins($gone, ['p5' => $result('linkedin', 'deleted')]);

        $events = $this->action()->handle($workspace, 2026, 8);

        $this->assertSame(['P-80', 'BV-80', 'P-81'], array_column($events, 'human_id'));
        $this->assertSame(['scheduled', 'published', 'scheduled'], array_column($events, 'state'));
        $this->assertSame('2026-08-26T09:00:00+06:00', $events[0]['at']);
        $this->assertSame('2026-08-27T09:00:00+06:00', $events[2]['at']);
    }

    public function test_it_reads_only_the_socialreins_rows_of_the_visible_month_in_one_query_per_kind(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        $socialreins = function (Post $post, ?string $scheduledAt, array $result) use ($workspace): void {
            SocialreinsGroup::query()->create([
                'workspace_id' => $workspace->id, 'subject_type' => 'post', 'subject_id' => $post->id,
                'language' => 'bangla', 'sequence' => 1, 'idempotency_key' => 'k'.$post->human_id, 'state' => 'created',
                'request' => json_encode(['scheduled_at' => $scheduledAt, 'posts' => [['body' => str_repeat('x', 500)]]]),
                'expected_targets' => 1,
                'results' => ['p'.$post->id => ['platform' => 'linkedin', 'status' => 'scheduled', 'error' => null, 'at' => 0, 'targets' => [], ...$result]],
            ]);
        };
        $post = fn (string $humanId, string $status = 'scheduled'): Post => Post::factory()->for($workspace)
            ->create(['title' => $humanId, 'human_id' => $humanId, 'status' => $status, 'postsyncer' => null]);

        // Created for July, then rescheduled into August.
        $socialreins($moved = $post('P-90'), '2026-07-10T03:00:00Z', ['scheduled_at' => '2026-08-12T03:00:00Z']);
        // Published at once: no scheduled time, only when its result was stored.
        $socialreins($live = $post('P-91', 'posted'), null, ['status' => 'published', 'at' => CarbonImmutable::parse('2026-08-13T03:00:00Z')->getTimestamp()]);
        // Another month, and a group whose results were emptied.
        $socialreins($post('P-92'), '2026-11-10T03:00:00Z', []);
        SocialreinsGroup::query()->create([
            'workspace_id' => $workspace->id, 'subject_type' => 'post', 'subject_id' => $post('P-93')->id,
            'language' => 'bangla', 'sequence' => 1, 'idempotency_key' => 'kP-93', 'state' => 'created',
            'request' => json_encode(['scheduled_at' => null]), 'results' => [],
        ]);

        $loaded = [];
        DB::listen(function (QueryExecuted $query) use (&$loaded): void {
            if (str_contains($query->sql, 'from "socialreins_groups"')) {
                $loaded[] = $query->sql;
            }
        });

        $events = $this->action()->handle($workspace, 2026, 8);

        $this->assertSame(['P-90', 'P-91'], array_column($events, 'human_id'));
        $this->assertSame(['scheduled', 'published'], array_column($events, 'state'));
        // One for posts, one for videos, neither selecting the whole stored request.
        $this->assertCount(2, $loaded);
        $this->assertStringNotContainsString('select *', $loaded[0]);
        $this->assertSame(
            [$moved->id, $live->id],
            array_keys(PublishGroups::rowsBySubject('post', PublishGroups::subjectsBetween(
                $workspace, 'post', CarbonImmutable::parse('2026-07-26T00:00:00+06:00'), CarbonImmutable::parse('2026-09-05T23:59:59+06:00'),
            ))),
        );
    }
}
