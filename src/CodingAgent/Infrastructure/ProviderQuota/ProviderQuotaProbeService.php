<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaReportDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaWindowDTO;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * App-owned `/usage` provider quota probe.
 *
 * Probes only providers present+enabled in effective AppConfig.
 * Codex credentials come from CodexAuthStorage; z.ai uses plain/env API keys.
 * Failures degrade to one sanitized section line; secrets never leave this service.
 */
final class ProviderQuotaProbeService implements ProviderQuotaProbeServiceInterface
{
    private const string OPENAI_USAGE = 'https://chatgpt.com/backend-api/wham/usage';
    private const string ZAI = 'zai';
    private const string ZAI_QUOTA = 'https://api.z.ai/api/monitor/usage/quota/limit';
    private const string ZAI_MODELS = 'https://api.z.ai/api/coding/paas/v4/models';

    public function __construct(
        private readonly CodexAuthStorage $codexAuthStorage,
        private readonly AppConfig $appConfig,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function probe(): ProviderQuotaReportDTO
    {
        $sections = [];
        $openAi = $this->enabled(CodexOAuthConfig::PROVIDER_KEY);
        $zai = $this->enabled(self::ZAI);

        // Dispatch configured requests first so Symfony HttpClient runs them concurrently.
        $openAiKey = CodexOAuthConfig::PROVIDER_KEY;
        $openAiPending = null;
        if (null !== $openAi) {
            $openAiKey = (null !== $openAi->authKey && '' !== trim($openAi->authKey))
                ? trim($openAi->authKey)
                : CodexOAuthConfig::PROVIDER_KEY;
            $openAiPending = $this->startOpenAi($openAiKey);
        }
        $zaiPending = null !== $zai ? $this->startZai($zai) : null;

        if (null !== $openAi) {
            $sections[] = $openAiPending instanceof ResponseInterface
                ? $this->finishOpenAi($openAiPending, $openAiKey)
                : $openAiPending;
        }
        if (null !== $zai) {
            $sections[] = $zaiPending instanceof ProviderQuotaSectionDTO
                ? $zaiPending
                : $this->finishZai($zaiPending);
        }

        return new ProviderQuotaReportDTO($sections);
    }

    private function enabled(string $id): ?AiProviderConfig
    {
        $provider = $this->appConfig->catalog?->getProvider($id);

        return null !== $provider && $provider->enabled ? $provider : null;
    }

    private function startOpenAi(string $authKey): ResponseInterface|ProviderQuotaSectionDTO
    {
        try {
            $record = $this->codexAuthStorage->loadCredentials($authKey);
        } catch (\Throwable $e) {
            $this->log('openai', 'credential_load_failed', $e);

            return $this->openAiAuthError($authKey);
        }
        if (null === $record || '' === trim($record->access)) {
            return $this->openAiAuthError($authKey);
        }

        $headers = [
            'Authorization' => 'Bearer '.trim($record->access),
            'Accept' => 'application/json',
        ];
        if ('' !== $record->accountId) {
            $headers['ChatGPT-Account-ID'] = $record->accountId;
        }

        return $this->httpClient->request('GET', self::OPENAI_USAGE, ['headers' => $headers]);
    }

    private function finishOpenAi(ResponseInterface $response, string $authKey): ProviderQuotaSectionDTO
    {
        $status = $this->status($response, 'openai');
        if (null === $status) {
            return new ProviderQuotaSectionDTO(title: 'OpenAI Codex', error: 'OpenAI usage probe failed.');
        }
        if (401 === $status) {
            return $this->openAiAuthError($authKey, expired: true);
        }
        if (429 === $status) {
            return new ProviderQuotaSectionDTO(title: 'OpenAI Codex', note: 'OpenAI usage endpoint is rate-limited.');
        }
        if ($status < 200 || $status >= 300) {
            return new ProviderQuotaSectionDTO(title: 'OpenAI Codex', error: \sprintf('OpenAI usage endpoint returned %d.', $status));
        }
        $payload = $this->json($response, 'openai');
        if (null === $payload) {
            return new ProviderQuotaSectionDTO(title: 'OpenAI Codex', error: 'OpenAI usage response was malformed.');
        }

        $windows = [];
        $group = $payload['rate_limit'] ?? null;
        if (\is_array($group)) {
            foreach (['primary_window' => 'primary', 'secondary_window' => 'secondary'] as $key => $fallback) {
                $raw = $group[$key] ?? null;
                if (!\is_array($raw)) {
                    continue;
                }
                $used = $this->num($raw['used_percent'] ?? null);
                if (null === $used) {
                    continue;
                }
                $seconds = $this->num($raw['limit_window_seconds'] ?? null);
                $suffix = null !== $seconds && $seconds > 0 ? $this->windowLabel((int) round($seconds)) : $fallback;
                $resetAfter = $this->num($raw['reset_after_seconds'] ?? null);
                $reset = null;
                if (null !== $resetAfter) {
                    $reset = $resetAfter <= 0 ? 'now' : 'in '.$this->duration($resetAfter * 1000.0);
                }
                $windows[] = new ProviderQuotaWindowDTO(
                    'Codex ('.$suffix.')',
                    max(0.0, min(100.0, 100.0 - $used)),
                    $reset,
                );
            }
        }

        return new ProviderQuotaSectionDTO(
            title: 'OpenAI Codex',
            windows: $windows,
            plan: $this->str($payload['plan_type'] ?? null),
            account: $this->str($payload['email'] ?? null),
            note: [] === $windows ? 'OpenAI response did not include window data.' : null,
        );
    }

    /**
     * @return array{quota: ResponseInterface, models: ?ResponseInterface, token: string}|ProviderQuotaSectionDTO
     */
    private function startZai(AiProviderConfig $provider): array|ProviderQuotaSectionDTO
    {
        $token = $this->resolveApiKey($provider->apiKey);
        if (null === $token) {
            $hint = null !== $provider->apiKey && str_starts_with($provider->apiKey, 'env:')
                ? \sprintf('Configured, but %s could not be resolved.', substr($provider->apiKey, 4))
                : 'Configured, but API key could not be resolved.';

            return new ProviderQuotaSectionDTO(title: 'z.ai', error: $hint);
        }

        $quota = $this->get(self::ZAI_QUOTA, $token);
        $models = null;
        try {
            $models = $this->get(self::ZAI_MODELS, $token);
        } catch (\Throwable $e) {
            $this->log('zai', 'models_request_failed', $e);
        }

        return ['quota' => $quota, 'models' => $models, 'token' => $token];
    }

    /**
     * @param array{quota: ResponseInterface, models: ?ResponseInterface, token: string} $pending
     */
    private function finishZai(array $pending): ProviderQuotaSectionDTO
    {
        $status = $this->status($pending['quota'], 'zai');
        if (null === $status) {
            return new ProviderQuotaSectionDTO(title: 'z.ai', error: 'z.ai usage probe failed.');
        }

        $quota = $pending['quota'];
        $models = $pending['models'];
        if ((401 === $status || 403 === $status) && 1 !== preg_match('/^bearer\s+/i', $pending['token'])) {
            $quota = $this->get(self::ZAI_QUOTA, 'Bearer '.$pending['token']);
            $status = $this->status($quota, 'zai') ?? 0;
            if ($status >= 200 && $status < 300) {
                try {
                    $models = $this->get(self::ZAI_MODELS, 'Bearer '.$pending['token']);
                } catch (\Throwable $e) {
                    $this->log('zai', 'models_auth_retry_failed', $e);
                }
            }
        }
        if (401 === $status || 403 === $status || 0 === $status) {
            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                modelCount: $this->modelCount($models),
                error: 'z.ai API key rejected — check ai.providers.zai.api_key / ZAI_API_KEY.',
            );
        }

        $modelCount = $this->modelCount($models);
        if (429 === $status) {
            return new ProviderQuotaSectionDTO(title: 'z.ai', note: 'z.ai quota endpoint is rate-limited.', modelCount: $modelCount);
        }
        if ($status < 200 || $status >= 300) {
            return new ProviderQuotaSectionDTO(title: 'z.ai', error: \sprintf('z.ai quota endpoint returned %d.', $status), modelCount: $modelCount);
        }
        $payload = $this->json($quota, 'zai');
        if (null === $payload) {
            return new ProviderQuotaSectionDTO(title: 'z.ai', error: 'z.ai quota response was malformed.', modelCount: $modelCount);
        }

        $success = true === ($payload['success'] ?? false);
        $code = \array_key_exists('code', $payload) ? $this->num($payload['code']) : null;
        if (!$success || (null !== $code && 200.0 !== $code)) {
            $msg = \is_string($payload['msg'] ?? null) ? trim(explode("\n", $payload['msg'], 2)[0]) : 'Unknown z.ai error';
            if (mb_strlen($msg) > 120) {
                $msg = mb_substr($msg, 0, 120).'…';
            }

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                modelCount: $modelCount,
                error: \sprintf('z.ai quota query failed (%s): %s', null !== $code ? (string) (int) $code : '?', $msg),
            );
        }

        $windows = [];
        $limits = $payload['data']['limits'] ?? null;
        if (\is_array($limits)) {
            foreach ($limits as $limit) {
                if (!\is_array($limit)) {
                    continue;
                }
                $type = \is_string($limit['type'] ?? null) ? strtoupper($limit['type']) : '';
                $total = $this->num($limit['usage'] ?? null);
                $used = $this->num($limit['currentValue'] ?? null);
                $usedPercent = $this->num($limit['percentage'] ?? null)
                    ?? (null !== $total && $total > 0 && null !== $used ? ($used / $total) * 100.0 : null);
                if (null === $usedPercent) {
                    continue;
                }
                $label = match ($type) {
                    'TOKENS_LIMIT' => 'Tokens',
                    'TIME_LIMIT' => 'Time',
                    default => 'Quota',
                };
                if (null !== $total && null !== $used) {
                    $label .= \sprintf(' (%s/%s)', number_format((int) round($used)), number_format((int) round($total)));
                }
                $resetMs = $this->num($limit['nextResetTime'] ?? null);
                $reset = null;
                if (null !== $resetMs) {
                    $diff = $resetMs - (microtime(true) * 1000.0);
                    $reset = $diff <= 0 ? 'now' : 'in '.$this->duration($diff);
                }
                $windows[] = new ProviderQuotaWindowDTO($label, max(0.0, min(100.0, 100.0 - $usedPercent)), $reset);
            }
        }

        return new ProviderQuotaSectionDTO(
            title: 'z.ai',
            windows: $windows,
            modelCount: $modelCount,
            note: [] === $windows ? 'z.ai quota response did not include usable window data.' : null,
        );
    }

    private function modelCount(?ResponseInterface $response): ?int
    {
        if (null === $response) {
            return null;
        }
        $status = $this->status($response, 'zai');
        if (null === $status || $status < 200 || $status >= 300) {
            return null;
        }
        $payload = $this->json($response, 'zai');

        return \is_array($payload['data'] ?? null) ? \count($payload['data']) : null;
    }

    private function get(string $url, string $authorization): ResponseInterface
    {
        return $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => $authorization,
                'Accept' => 'application/json',
                'User-Agent' => 'hatfield-usage/1.0',
            ],
        ]);
    }

    private function status(ResponseInterface $response, string $provider): ?int
    {
        try {
            return $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->log($provider, 'transport_failed', $e);

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function json(ResponseInterface $response, string $provider): ?array
    {
        try {
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);

            return $payload;
        } catch (\Throwable $e) {
            $this->log($provider, 'malformed_response', $e);

            return null;
        }
    }

    private function resolveApiKey(?string $apiKey): ?string
    {
        if (null === $apiKey || '' === trim($apiKey)) {
            return null;
        }
        if (str_starts_with($apiKey, 'env:')) {
            $var = substr($apiKey, 4);
            if ('' === $var) {
                return null;
            }
            $value = getenv($var);

            return false !== $value && '' !== $value ? $value : null;
        }

        return trim($apiKey);
    }

    private function openAiAuthError(string $authKey, bool $expired = false): ProviderQuotaSectionDTO
    {
        $hint = CodexOAuthConfig::authCommandHintForProviderKey($authKey);

        return new ProviderQuotaSectionDTO(
            title: 'OpenAI Codex',
            error: $expired
                ? \sprintf('OpenAI auth token expired — run %s.', $hint)
                : \sprintf('Auth token unavailable/expired (run: %s).', $hint),
        );
    }

    private function str(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function num(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (\is_string($value) && is_numeric($value)) {
            $parsed = (float) $value;

            return is_finite($parsed) ? $parsed : null;
        }

        return null;
    }

    private function windowLabel(int $seconds): string
    {
        if (0 === $seconds % 3600) {
            $hours = intdiv($seconds, 3600);

            return 0 === $hours % 24 ? intdiv($hours, 24).'d' : $hours.'h';
        }

        return 0 === $seconds % 60 ? intdiv($seconds, 60).'m' : $seconds.'s';
    }

    private function duration(float $ms): string
    {
        $seconds = (int) floor(max(0.0, $ms) / 1000.0);
        if ($seconds < 60) {
            return $seconds.'s';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            $rem = $seconds % 60;

            return $minutes.'m'.($rem > 0 ? $rem.'s' : '');
        }
        $hours = intdiv($minutes, 60);
        $remM = $minutes % 60;

        return $hours.'h'.($remM > 0 ? $remM.'m' : '');
    }

    private function log(string $provider, string $eventType, \Throwable $e): void
    {
        $this->logger->warning('Provider quota probe degraded', [
            'component' => 'provider_quota_probe',
            'event_type' => $eventType,
            'provider' => $provider,
            'exception_class' => $e::class,
        ]);
    }
}
