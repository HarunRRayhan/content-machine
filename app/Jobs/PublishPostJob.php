<?php

namespace App\Jobs;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     */
    public function __construct(
        public readonly Post $post,
        public readonly array $options = [],
    ) {}

    public function handle(PublishPostAction $action): void
    {
        $action->handle($this->post, $this->options);
    }
}
