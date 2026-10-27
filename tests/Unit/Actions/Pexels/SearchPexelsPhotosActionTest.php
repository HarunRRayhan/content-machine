<?php

namespace Tests\Unit\Actions\Pexels;

use App\Actions\Pexels\SearchPexelsPhotosAction;
use App\Contracts\PexelsProvider;
use App\Data\Pexels\PexelsPhotoData;
use App\Data\Pexels\SearchPexelsPhotosData;
use Mockery;
use Tests\TestCase;

class SearchPexelsPhotosActionTest extends TestCase
{
    public function test_it_searches_with_the_requested_filters_and_returns_provider_order(): void
    {
        $first = new PexelsPhotoData(
            id: 123,
            photographer: 'A Photographer',
            photographerUrl: 'https://www.pexels.com/@photographer/',
            pexelsUrl: 'https://www.pexels.com/photo/123/',
            previewUrl: 'https://images.pexels.com/photos/123/medium.jpg',
            downloadUrl: 'https://images.pexels.com/photos/123/original.jpg',
        );
        $second = new PexelsPhotoData(
            id: 456,
            photographer: 'Another Photographer',
            photographerUrl: 'https://www.pexels.com/@another/',
            pexelsUrl: 'https://www.pexels.com/photo/456/',
            previewUrl: 'https://images.pexels.com/photos/456/medium.jpg',
            downloadUrl: 'https://images.pexels.com/photos/456/original.jpg',
        );
        $provider = Mockery::mock(PexelsProvider::class);
        $provider->shouldReceive('search')
            ->once()
            ->with('street', 'portrait', 6)
            ->andReturn([$first, $second]);

        $photos = (new SearchPexelsPhotosAction)->handle(
            new SearchPexelsPhotosData('street', 'portrait', 6),
            $provider,
        );

        $this->assertSame([$first, $second], $photos);
    }
}
