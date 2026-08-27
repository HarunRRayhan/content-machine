<?php

namespace Tests\Unit\Support\GoogleDrive;

use App\Support\GoogleDrive\GoogleDriveLink;
use Tests\TestCase;

class GoogleDriveLinkTest extends TestCase
{
    public function test_parses_file_view_links(): void
    {
        $link = GoogleDriveLink::parse('https://drive.google.com/file/d/abc123XYZ/view?usp=sharing');

        $this->assertNotNull($link);
        $this->assertSame('abc123XYZ', $link->fileId);
        $this->assertSame(
            'https://drive.google.com/file/d/abc123XYZ/view?usp=sharing',
            $link->shareUrl(),
        );
        $this->assertSame(
            'https://drive.usercontent.google.com/download?id=abc123XYZ&export=download&confirm=t',
            $link->fetchUrl(),
        );
    }

    public function test_parses_open_and_uc_query_ids(): void
    {
        $this->assertSame(
            'fileOne',
            GoogleDriveLink::parse('https://drive.google.com/open?id=fileOne')?->fileId,
        );
        $this->assertSame(
            'fileTwo',
            GoogleDriveLink::parse('https://drive.google.com/uc?export=download&id=fileTwo')?->fileId,
        );
        $this->assertSame(
            'fileThree',
            GoogleDriveLink::parse('https://drive.usercontent.google.com/download?id=fileThree&export=download')?->fileId,
        );
    }

    public function test_rejects_folders_and_non_drive_urls(): void
    {
        $this->assertTrue(GoogleDriveLink::isFolder('https://drive.google.com/drive/folders/abc123XYZ'));
        $this->assertNull(GoogleDriveLink::parse('https://drive.google.com/drive/folders/abc123XYZ'));
        $this->assertNull(GoogleDriveLink::parse('https://example.com/file.mp4'));
        $this->assertFalse(GoogleDriveLink::looksLikeDrive('https://example.com/file.mp4'));
    }

    public function test_to_fetch_url_leaves_non_drive_urls_alone(): void
    {
        $this->assertSame(
            'https://cdn.example.com/cover.jpg',
            GoogleDriveLink::toFetchUrl('https://cdn.example.com/cover.jpg'),
        );
        $this->assertSame(
            'https://drive.usercontent.google.com/download?id=video&export=download&confirm=t',
            GoogleDriveLink::toFetchUrl('https://drive.google.com/file/d/video/view'),
        );
    }
}
