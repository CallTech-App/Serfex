<?php

namespace Tests\Support;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\ReadOnlyGuard;
use Closure;

/**
 * An agent that records calls and answers from a map of method => answer
 * (a value, or a closure taking the params; an AgentException is thrown).
 */
class FakeAgent extends AgentClient
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param  array<string, mixed>  $answers
     */
    public function __construct(public array $answers = [])
    {
        parent::__construct('/nonexistent.sock', 1);
    }

    public function call(string $method, array $params = [], ?float $timeout = null): mixed
    {
        ReadOnlyGuard::agent($method);
        $this->calls[] = [$method, $params];
        $answer = array_key_exists($method, $this->answers) ? $this->answers[$method] : [];
        if ($answer instanceof Closure) {
            $answer = $answer($params);
        }
        if ($answer instanceof AgentException) {
            throw $answer;
        }

        return $answer;
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * The params of every call to $method.
     *
     * @return list<array<string, mixed>>
     */
    public function called(string $method): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->calls, fn ($c) => $c[0] === $method)));
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_map(fn ($c) => $c[0], $this->calls);
    }
}
