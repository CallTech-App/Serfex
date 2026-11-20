<?php

namespace App\Services\Security;

use App\Services\Monitor\MonitorException;

/**
 * The whole server cannot become the scope of the country block: the
 * caller's own country is blocked, and the caller would be shut out.
 */
class ScopeRefused extends MonitorException {}
