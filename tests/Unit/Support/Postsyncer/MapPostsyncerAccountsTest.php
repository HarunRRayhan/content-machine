<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Support\Postsyncer\MapPostsyncerAccounts;
use PHPUnit\Framework\TestCase;

class MapPostsyncerAccountsTest extends TestCase
{
    public function test_present_normalizes_x_to_twitter_and_prefixes_handles(): void
    {
        $presented = MapPostsyncerAccounts::present([
            ['id' => 1205, 'platform' => 'x', 'username' => 'harundotdev'],
            ['id' => 7017, 'platform' => 'facebook', 'handle' => '@HarunRRayhan'],
            ['id' => 7360, 'platform' => 'linkedin', 'username' => null, 'name' => 'Harunur Rashid'],
            ['id' => '', 'platform' => 'instagram'],
        ], '853');

        $this->assertSame([
            ['id' => '1205', 'platform' => 'twitter', 'handle' => '@harundotdev'],
            ['id' => '7017', 'platform' => 'facebook', 'handle' => '@HarunRRayhan'],
            ['id' => '7360', 'platform' => 'linkedin', 'handle' => '@harundotdev'],
        ], $presented);
    }

    public function test_present_does_not_use_a_display_name_as_a_handle(): void
    {
        $presented = MapPostsyncerAccounts::present([
            ['id' => 7360, 'platform' => 'linkedin', 'username' => null, 'name' => 'Harunur Rashid'],
        ], '42761');

        $this->assertSame([
            ['id' => '7360', 'platform' => 'linkedin', 'handle' => ''],
        ], $presented);
    }

    public function test_to_platforms_enables_found_accounts_and_keeps_existing_toggles(): void
    {
        $suggested = MapPostsyncerAccounts::toPlatforms(
            ['facebook', 'instagram', 'twitter'],
            [
                ['id' => 1205, 'platform' => 'x', 'username' => 'harundotdev'],
                ['id' => 9, 'platform' => 'facebook', 'username' => 'page'],
            ],
            [
                'facebook' => ['account_id' => 'old', 'handle' => '@old', 'enabled' => false],
            ],
        );

        $this->assertFalse($suggested['facebook']['enabled']);
        $this->assertSame(9, $suggested['facebook']['account_id']);
        $this->assertSame('@page', $suggested['facebook']['handle']);
        $this->assertTrue($suggested['twitter']['enabled']);
        $this->assertSame(1205, $suggested['twitter']['account_id']);
        $this->assertFalse($suggested['instagram']['enabled']);
    }
}
