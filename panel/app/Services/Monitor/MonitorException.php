<?php

namespace App\Services\Monitor;

use RuntimeException;

/**
 * A monitoring action that cannot be done as asked; the message is for
 * the person (or Servik AI) who asked.
 */
class MonitorException extends RuntimeException {}
