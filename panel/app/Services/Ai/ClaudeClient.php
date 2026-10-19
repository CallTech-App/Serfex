<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use GuzzleHttp\Client as Guzzle;
use Psr\Http\Client\ClientInterface;

/**
 * Sends Servik AI's requests to the Claude API with the official SDK.
 * Content blocks travel as the API's own JSON arrays, so they can be
 * stored and sent back unchanged.
 */
class ClaudeClient
{
    /** Opts into the API's refusal fallbacks ("fallbacks": "default"). */
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @param  ClientInterface|null  $transporter  the HTTP client; tests pass a fake one
     */
    public function __construct(private readonly AiSettings $settings, private readonly ?ClientInterface $transporter = null) {}

    /**
     * One request to the Messages API.
     *
     * @param  list<array<string, mixed>>  $system
     * @param  list<array<string, mixed>>  $tools
     * @param  list<array<string, mixed>>  $messages
     * @return array{content: list<array<string, mixed>>, stop_reason: ?string, model: string,
     *     usage: array{input: int, output: int, cache_read: int, cache_write: int}}
     *
     * @throws ClaudeException
     */
    public function send(string $model, array $system, array $tools, array $messages): array
    {
        $key = $this->settings->apiKey() ?? throw new ClaudeException('Claude API anahtarı kayıtlı değil.');
        $fallbacks = Models::hasFallbacks($model);

        try {
            $message = $this->client($key)->beta->messages->create(
                maxTokens: (int) config('servik.ai.max_tokens'),
                messages: $messages,
                model: $model,
                // Caches the system prompt, the tools and the conversation so far.
                cacheControl: ['type' => 'ephemeral'],
                fallbacks: $fallbacks ? 'default' : null,
                outputConfig: ['effort' => 'medium'],
                system: $system,
                tools: $tools,
                betas: $fallbacks ? [self::FALLBACK_BETA] : null,
            );
        } catch (APIException $e) {
            throw new ClaudeException(self::explain($e), previous: $e);
        }

        $data = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $usage = $data['usage'] ?? [];

        return [
            'content' => array_values($data['content'] ?? []),
            'stop_reason' => $data['stop_reason'] ?? null,
            'model' => (string) ($data['model'] ?? $model),
            'usage' => [
                'input' => (int) ($usage['input_tokens'] ?? 0),
                'output' => (int) ($usage['output_tokens'] ?? 0),
                'cache_read' => (int) ($usage['cache_read_input_tokens'] ?? 0),
                'cache_write' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * Checks that a key works with a model.
     *
     * @throws ClaudeException
     */
    public function check(string $key, string $model): void
    {
        try {
            $this->client($key)->models->retrieve($model);
        } catch (APIException $e) {
            throw new ClaudeException(self::explain($e), previous: $e);
        }
    }

    private function client(string $key): Client
    {
        return new Client(
            apiKey: $key,
            baseUrl: config('servik.ai.base_url'),
            requestOptions: [
                'transporter' => $this->transporter ?? new Guzzle([
                    'timeout' => (float) config('servik.ai.timeout'),
                    'connect_timeout' => 10,
                ]),
                'maxRetries' => 2,
            ],
            openTelemetry: false,
        );
    }

    /**
     * A message for the panel, in Turkish.
     */
    public static function explain(APIException $e): string
    {
        return match (true) {
            $e instanceof AuthenticationException => 'Claude API anahtarı geçersiz. Servik AI ayarlarından anahtarı kontrol edin.',
            $e instanceof PermissionDeniedException => 'Bu API anahtarının seçili modeli kullanma izni yok.',
            $e instanceof NotFoundException => 'Seçili model bu hesapta bulunamadı. Ayarlardan başka bir model seçin.',
            $e instanceof RateLimitException => 'Claude hesabınızın kullanım sınırına ulaşıldı. Biraz sonra yeniden deneyin.',
            $e instanceof BadRequestException && str_contains(strtolower($e->getMessage()), 'credit') => 'Claude hesabınızda yeterli kredi yok.',
            $e instanceof BadRequestException => 'Claude isteği kabul etmedi: '.mb_substr(self::apiMessage($e), 0, 300),
            $e instanceof APITimeoutException => 'Claude zamanında yanıt vermedi. Yeniden deneyin.',
            $e instanceof APIConnectionException => 'Claude API\'ye bağlanılamadı. Sunucunun api.anthropic.com adresine çıkabildiğini kontrol edin.',
            ($e->status ?? 0) >= 500 => 'Claude şu an yanıt veremiyor (yoğunluk ya da bakım). Biraz sonra yeniden deneyin.',
            default => 'Claude isteği başarısız oldu: '.mb_substr(self::apiMessage($e), 0, 300),
        };
    }

    private static function apiMessage(APIException $e): string
    {
        $body = is_string($e->body) ? json_decode($e->body, true) : $e->body;
        if (is_array($body) && is_string($body['error']['message'] ?? null)) {
            return $body['error']['message'];
        }

        return $e->getMessage();
    }
}
