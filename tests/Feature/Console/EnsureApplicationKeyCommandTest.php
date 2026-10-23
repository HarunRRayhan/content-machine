<?php

use Illuminate\Support\Facades\File;

it('preserves an existing application key and environment file', function () {
    config(['app.key' => 'existing-key']);
    $original = File::get(app()->environmentFilePath());

    $this->artisan('cm:ensure-key')->assertSuccessful();

    expect(config('app.key'))->toBe('existing-key');
    expect(File::get(app()->environmentFilePath()))->toBe($original);
});

it('requires an existing key for production setup', function () {
    config(['app.key' => null]);

    $this->artisan('cm:ensure-key --require-existing')->assertFailed();

    expect(config('app.key'))->toBeNull();
});

it('does not generate a key in a production environment', function () {
    config(['app.key' => null]);
    app()->instance('env', 'production');

    $this->artisan('cm:ensure-key')->assertFailed();

    expect(config('app.key'))->toBeNull();
});

it('generates a missing local key once without rotating it on a second run', function () {
    $directory = sys_get_temp_dir().'/cm-key-'.bin2hex(random_bytes(8));
    File::makeDirectory($directory);
    File::put($directory.'/.env', "APP_KEY=\n");
    $originalPath = app()->environmentPath();
    app()->useEnvironmentPath($directory);
    config(['app.key' => null]);

    try {
        $this->artisan('cm:ensure-key')->assertSuccessful();
        $key = config('app.key');
        $contents = File::get($directory.'/.env');

        expect($key)->toStartWith('base64:');
        expect($contents)->toContain('APP_KEY='.$key);

        $this->artisan('cm:ensure-key')->assertSuccessful();

        expect(config('app.key'))->toBe($key);
        expect(File::get($directory.'/.env'))->toBe($contents);
    } finally {
        app()->useEnvironmentPath($originalPath);
        File::deleteDirectory($directory);
    }
});
