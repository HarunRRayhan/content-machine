<?php

namespace Tests\Unit\Concerns;

use App\Concerns\ResolvesByHumanId;
use PHPUnit\Framework\TestCase;

class ResolvesByHumanIdTest extends TestCase
{
    public function test_prefixed_values_are_custom_ids(): void
    {
        $this->assertTrue(ResolvesByHumanIdProbe::looksLikeHumanId('P-50'));
        $this->assertTrue(ResolvesByHumanIdProbe::looksLikeHumanId('BP-12'));
        $this->assertTrue(ResolvesByHumanIdProbe::looksLikeHumanId('EP-1'));
        $this->assertTrue(ResolvesByHumanIdProbe::looksLikeHumanId('BV-46'));
        $this->assertTrue(ResolvesByHumanIdProbe::looksLikeHumanId('EV-3'));
        $this->assertTrue(ResolvesByHumanIdProbe::looksLikeHumanId('V-12'));
    }

    public function test_numeric_and_other_values_are_not_custom_ids(): void
    {
        $this->assertFalse(ResolvesByHumanIdProbe::looksLikeHumanId('59'));
        $this->assertFalse(ResolvesByHumanIdProbe::looksLikeHumanId(59));
        $this->assertFalse(ResolvesByHumanIdProbe::looksLikeHumanId('P50'));
        $this->assertFalse(ResolvesByHumanIdProbe::looksLikeHumanId('posts'));
        $this->assertFalse(ResolvesByHumanIdProbe::looksLikeHumanId('P-50-extra'));
    }
}

class ResolvesByHumanIdProbe
{
    use ResolvesByHumanId;
}
