<?php

namespace Tests\Unit\Support\GoogleDrive;

use App\Support\GoogleDrive\GoogleDriveLinkChecker;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDriveLinkCheckerTest extends TestCase
{
    private GoogleDriveLinkChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checker = new GoogleDriveLinkChecker;
    }

    public function test_public_media_response_is_accessible(): void
    {
        Http::fake([
            'drive.usercontent.google.com/*' => Http::response('bytes', 200, [
                'Content-Type' => 'video/mp4',
            ]),
        ]);

        $result = $this->checker->check('https://drive.google.com/file/d/publicFile/view');

        $this->assertTrue($result->ok);
        $this->assertSame('publicFile', $result->fileId);
        $this->assertSame(
            'https://drive.usercontent.google.com/download?id=publicFile&export=download&confirm=t',
            $result->fetchUrl,
        );
    }

    public function test_virus_scan_html_is_accessible(): void
    {
        Http::fake([
            'drive.usercontent.google.com/*' => Http::response(
                '<form id="download-form">Google Drive - Virus scan warning</form>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $result = $this->checker->check('https://drive.google.com/file/d/bigFile/view');

        $this->assertTrue($result->ok);
    }

    public function test_private_html_is_rejected(): void
    {
        Http::fake([
            'drive.usercontent.google.com/*' => Http::response(
                'You need access. Request access from the owner.',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $result = $this->checker->check('https://drive.google.com/file/d/privateFile/view');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('not public', $result->message);
    }

    public function test_http_errors_are_rejected(): void
    {
        Http::fake([
            'drive.usercontent.google.com/*' => Http::response('', 404),
        ]);

        $this->assertFalse($this->checker->check('https://drive.google.com/file/d/missing/view')->ok);
    }

    public function test_folder_links_are_rejected_without_http(): void
    {
        Http::fake();

        $result = $this->checker->check('https://drive.google.com/drive/folders/abc123XYZ');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('folder', $result->message);
        Http::assertNothingSent();
    }

    public function test_non_drive_urls_are_skipped(): void
    {
        Http::fake();

        $result = $this->checker->check('https://cdn.example.com/cover.jpg');

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('direct URL', $result->message);
        Http::assertNothingSent();
    }
}
