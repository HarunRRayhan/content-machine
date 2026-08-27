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
            ['id' => 7363, 'platform' => 'facebook', 'name' => 'Harun Dev'],
            ['id' => '', 'platform' => 'instagram'],
        ]);

        $this->assertSame([
            ['id' => '1205', 'platform' => 'twitter', 'handle' => '@harundotdev'],
            ['id' => '7017', 'platform' => 'facebook', 'handle' => '@HarunRRayhan'],
            ['id' => '7363', 'platform' => 'facebook', 'handle' => '@Harun Dev'],
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
