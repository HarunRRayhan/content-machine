<?php

namespace App\Actions\Notifications;

use RuntimeException;

class TelegramNotificationKeyConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The notification key was already used with different content or recipient.');
    }
}
