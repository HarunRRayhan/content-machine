<?php

use Illuminate\Foundation\DevCommands;

it('includes content workers and the scheduler in the development processes', function () {
    $commands = collect(DevCommands::commands())->pluck('command', 'name');

    expect($commands->get('queue'))->toBe('composer run worker:default');
    expect($commands->get('scratchpad'))->toBe('composer run worker:scratchpad');
    expect($commands->get('publishing'))->toBe('composer run worker:publish');
    expect($commands->get('scheduler'))->toBe('composer run scheduler');
});
