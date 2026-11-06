<?php

namespace Tests\Unit\Support\Socialreins;

use App\Support\Socialreins\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    public function test_it_matches_brains_known_vector(): void
    {
        // From brain's crates/core/src/webhook.rs, checked there with openssl.
        $header = 't=1700000000,v1=38877139021993b830af32feea6e18a8da83eb2f6e49ee50bd9e4cf4ca4d3789';

        $this->assertSame($header, WebhookSignature::sign('whsec_test', 1_700_000_000, '{"a":1}'));
        $this->assertTrue(WebhookSignature::verify('whsec_test', $header, '{"a":1}', 1_700_000_100));
    }

    public function test_it_rejects_a_changed_body_a_wrong_secret_and_malformed_headers(): void
    {
        $header = WebhookSignature::sign('whsec_test', 1_700_000_000, '{"a":1}');
        $now = 1_700_000_000;

        $this->assertFalse(WebhookSignature::verify('whsec_test', $header, '{"a":2}', $now));
        $this->assertFalse(WebhookSignature::verify('whsec_other', $header, '{"a":1}', $now));
        foreach ([null, '', 'v1=abc', 't=1700000000', 't=abc,v1=abc', 't=1700000000,v1='] as $bad) {
            $this->assertFalse(WebhookSignature::verify('whsec_test', $bad, '{"a":1}', $now));
        }
    }

    public function test_it_rejects_a_replay_outside_the_time_window(): void
    {
        $header = WebhookSignature::sign('whsec_test', 1_700_000_000, 'x');

        $this->assertTrue(WebhookSignature::verify('whsec_test', $header, 'x', 1_700_000_000 + 300));
        $this->assertFalse(WebhookSignature::verify('whsec_test', $header, 'x', 1_700_000_000 + 301));
        $this->assertFalse(WebhookSignature::verify('whsec_test', $header, 'x', 1_700_000_000 - 301));
    }
}
