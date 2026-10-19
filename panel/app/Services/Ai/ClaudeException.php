<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * A request to Claude that failed, with a message for the panel.
 */
class ClaudeException extends RuntimeException {}
