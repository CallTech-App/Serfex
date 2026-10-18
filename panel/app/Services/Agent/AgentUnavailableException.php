<?php

namespace App\Services\Agent;

/**
 * The agent could not be reached at all (not running, wrong socket, no
 * permission), as opposed to answering with an error.
 */
class AgentUnavailableException extends AgentException {}
