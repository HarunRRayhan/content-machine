<?php

namespace App\Actions\Notifications;

use RuntimeException;

class TelegramNotificationsUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Telegram notifications are not available for this workspace.');
    }
}
