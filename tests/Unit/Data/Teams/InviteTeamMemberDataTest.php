<?php

namespace Tests\Unit\Data\Teams;

use App\Data\Teams\InviteTeamMemberData;
use App\Http\Requests\Team\InviteTeamMemberRequest;
use Tests\TestCase;

class InviteTeamMemberDataTest extends TestCase
{
    public function test_from_request_reads_email_and_role()
    {
        $request = InviteTeamMemberRequest::create('/dashboard/team/invitations', 'POST', [
            'email' => 'teammate@example.com',
            'role' => 'admin',
        ]);

        $data = InviteTeamMemberData::fromRequest($request);

        $this->assertSame('teammate@example.com', $data->email);
        $this->assertSame('admin', $data->role);
    }
}
