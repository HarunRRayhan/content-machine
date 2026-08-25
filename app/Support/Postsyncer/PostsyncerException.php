<?php

namespace App\Support\Postsyncer;

use Exception;
use Illuminate\Http\Client\Response;

class PostsyncerException extends Exception
{
    public static function fromResponse(Response $response): self
    {
        $message = $response->json('message') ?? $response->json('error') ?? $response->body();

        if (! is_string($message)) {
            $message = json_encode($message) ?: 'Unknown error';
        }

        return new self("PostSyncer API error {$response->status()}: {$message}");
    }
}
