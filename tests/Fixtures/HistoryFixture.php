<?php

namespace Tests\Fixtures;

use App\Concerns\RecordsHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * Throwaway model for proving RecordsHistory writes to status_transitions
 * and content_versions correctly. Nothing in the app uses this; its table
 * is created/dropped by the test that exercises it
 * (tests/Unit/Concerns/RecordsHistoryTest.php).
 */
class HistoryFixture extends Model
{
    use RecordsHistory;

    protected $table = 'history_fixtures';

    protected $fillable = ['name'];
}
