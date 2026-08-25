<?php

namespace App\Jobs;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     */
    public function __construct(
        public readonly Video $video,
        public readonly array $options = [],
    ) {}

    public function handle(PublishVideoAction $action): void
    {
        $action->handle($this->video, $this->options);
    }
}
