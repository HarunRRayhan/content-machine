<?php

namespace Tests\Unit\Support\Socialreins;

use App\Support\Socialreins\SocialreinsResults;
use PHPUnit\Framework\TestCase;

class SocialreinsResultsTest extends TestCase
{
    public function test_a_retry_keeps_the_rescheduled_time_and_the_delete_attempt(): void
    {
        $existing = [
            'platform' => 'linkedin', 'status' => 'failed', 'error' => null, 'at' => 100,
            'scheduled_at' => '2026-12-03T12:30:00Z',
            'delete_attempt' => 2,
            'targets' => ['t1' => ['status' => 'failed', 'remote_id' => null, 'remote_url' => null, 'parts' => 0, 'error' => 'boom', 'at' => 100, 'unknown' => false]],
        ];
        $incoming = SocialreinsResults::fromBrainPost([
            'id' => 'p1', 'platform' => 'linkedin', 'status' => 'queued',
            'targets' => [['id' => 't1', 'status' => 'pending']],
        ], 200);

        $result = SocialreinsResults::afterRetry($existing, $incoming, 200, []);

        $this->assertSame('2026-12-03T12:30:00Z', $result['scheduled_at']);
        $this->assertSame(2, $result['delete_attempt']);
        $this->assertSame('queued', $result['status']);
        $this->assertSame(200, $result['reset_at']);
    }

    public function test_a_retry_of_a_post_that_was_never_rescheduled_adds_no_time(): void
    {
        $result = SocialreinsResults::afterRetry(
            ['targets' => []],
            SocialreinsResults::fromBrainPost(['id' => 'p1', 'platform' => 'linkedin', 'status' => 'queued'], 200),
            200,
            [],
        );

        $this->assertArrayNotHasKey('scheduled_at', $result);
        $this->assertArrayNotHasKey('delete_attempt', $result);
    }
}
