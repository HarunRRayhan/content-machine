<?php

namespace App\Support\Publishing;

use Exception;

/** A publish CM refuses before anything is sent: a planning, approval or request problem. */
class PublishException extends Exception {}
