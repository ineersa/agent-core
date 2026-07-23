<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaReportDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** App-owned `/usage` probe for configured OpenAI Codex and z.ai only. */
final class ProviderQuotaProbeService implements ProviderQuotaProbeServiceInterface
{
    private const string OPENAI_USAGE = 'https://chatgpt.com/backend-api/wham/usage';
    private const string ZAI_QUOTA = 'https://api.z.ai/api/monitor/usage/quota/limit';

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
        $openAiCfg = $this->appConfig->catalog?->getProvider(CodexOAuthConfig::PROVIDER_KEY);
        $openAi = null !== $openAiCfg && $openAiCfg->enabled ? $openAiCfg : null;
        $zaiCfg = $this->appConfig->catalog?->getProvider('zai');
        $zai = null !== $zaiCfg && $zaiCfg->enabled ? $zaiCfg : null;

        // Dispatch configured requests first so Symfony HttpClient can run them concurrently.
        $openAiResponse = null;
        $openAiAuthKey = CodexOAuthConfig::PROVIDER_KEY;
        $openAiEarly = null;
        if (null !== $openAi) {
            $openAiAuthKey = (null !== $openAi->authKey && '' !== trim($openAi->authKey))
                ? trim($openAi->authKey) : CodexOAuthConfig::PROVIDER_KEY;
            try {
                $record = $this->codexAuthStorage->loadCredentials($openAiAuthKey);
            } catch (\Throwable $e) {
                $this->logger->warning('Provider quota probe degraded', [
                    'component' => 'provider_quota_probe', 'event_type' => 'credential_load_failed',
                    'provider' => 'openai', 'exception_class' => $e::class,
                ]);
                $record = null;
            }
            if (null === $record || '' === trim($record->access)) {
                $openAiEarly = new ProviderQuotaSectionDTO('OpenAI Codex', ['- Error: '.\sprintf(
                    'Auth token unavailable/expired (run: %s).',
                    CodexOAuthConfig::authCommandHintForProviderKey($openAiAuthKey),
                )]);
            } else {
                $headers = ['Authorization' => 'Bearer '.trim($record->access), 'Accept' => 'application/json'];
                if ('' !== $record->accountId) {
                    $headers['ChatGPT-Account-ID'] = $record->accountId;
                }
                $openAiResponse = $this->httpClient->request('GET', self::OPENAI_USAGE, ['headers' => $headers]);
            }
        }

        $zaiResponse = null;
        $zaiEarly = null;
        if (null !== $zai) {
            $apiKey = $zai->apiKey;
            $token = null;
            if (null !== $apiKey && '' !== trim($apiKey)) {
                if (str_starts_with($apiKey, 'env:')) {
                    $var = substr($apiKey, 4);
                    $value = '' === $var ? false : getenv($var);
                    $token = false !== $value && '' !== $value ? $value : null;
                } else {
                    $token = trim($apiKey);
                }
            }
            if (null === $token) {
                $hint = null !== $apiKey && str_starts_with($apiKey, 'env:')
                    ? \sprintf('Configured, but %s could not be resolved.', substr($apiKey, 4))
                    : 'Configured, but API key could not be resolved.';
                $zaiEarly = new ProviderQuotaSectionDTO('z.ai', ['- Error: '.$hint]);
            } else {
                // Coding Plan monitor endpoint expects the API key verbatim (not Bearer like the chat API).
                $zaiResponse = $this->httpClient->request('GET', self::ZAI_QUOTA, [
                    'headers' => ['Authorization' => $token, 'Accept' => 'application/json', 'User-Agent' => 'hatfield-usage/1.0'],
                ]);
            }
        }

        // Early sections win; otherwise response is non-null by construction above.
        if (null !== $openAiEarly) {
            $sections[] = $openAiEarly;
        } elseif (null !== $openAiResponse) {
            $sections[] = $this->openAi($openAiResponse, $openAiAuthKey);
        }

        if (null !== $zaiEarly) {
            $sections[] = $zaiEarly;
        } elseif (null !== $zaiResponse) {
            $sections[] = $this->zai($zaiResponse);
        }

        return new ProviderQuotaReportDTO($sections);
    }

    private function openAi(ResponseInterface $response, string $authKey): ProviderQuotaSectionDTO
    {
        [$status, $payload] = $this->read($response, 'openai');
        if (null === $status) {
            return new ProviderQuotaSectionDTO('OpenAI Codex', ['- Error: OpenAI usage probe failed.']);
        }
        if (401 === $status) {
            return new ProviderQuotaSectionDTO('OpenAI Codex', ['- Error: '.\sprintf(
                'OpenAI auth token expired — run %s.',
                CodexOAuthConfig::authCommandHintForProviderKey($authKey),
            )]);
        }
        if ($status < 200 || $status >= 300 || null === $payload) {
            $msg = null === $payload && $status >= 200 && $status < 300
                ? 'OpenAI usage response was malformed.'
                : \sprintf('OpenAI usage endpoint returned %d.', $status);

            return new ProviderQuotaSectionDTO('OpenAI Codex', ['- Error: '.$msg]);
        }

        $lines = [];
        $window = \is_array($payload['rate_limit']['primary_window'] ?? null) ? $payload['rate_limit']['primary_window'] : null;
        $used = null === $window ? null : $this->num($window['used_percent'] ?? null);
        if (null !== $used) {
            $suffix = $this->windowLabel($this->num($window['limit_window_seconds'] ?? null));
            $lines[] = \sprintf(
                '- Codex (%s): %.0f%% left%s',
                $suffix,
                max(0.0, min(100.0, 100.0 - $used)),
                $this->resetSuffix($window['reset_after_seconds'] ?? null),
            );
        }
        foreach (['plan_type' => 'Plan', 'email' => 'Account'] as $field => $label) {
            $value = $payload[$field] ?? null;
            if (\is_string($value) && '' !== $value) {
                $lines[] = \sprintf('- %s: %s', $label, $value);
            }
        }

        return [] === $lines
            ? new ProviderQuotaSectionDTO('OpenAI Codex', ['- Error: OpenAI response did not include window data.'])
            : new ProviderQuotaSectionDTO('OpenAI Codex', $lines);
    }

    private function zai(ResponseInterface $response): ProviderQuotaSectionDTO
    {
        [$status, $payload] = $this->read($response, 'zai');
        if (null === $status) {
            return new ProviderQuotaSectionDTO('z.ai', ['- Error: z.ai usage probe failed.']);
        }
        if (401 === $status || 403 === $status) {
            return new ProviderQuotaSectionDTO('z.ai', ['- Error: z.ai API key rejected — check ai.providers.zai.api_key / ZAI_API_KEY.']);
        }
        if ($status < 200 || $status >= 300 || null === $payload) {
            $msg = null === $payload && $status >= 200 && $status < 300
                ? 'z.ai quota response was malformed.'
                : \sprintf('z.ai quota endpoint returned %d.', $status);

            return new ProviderQuotaSectionDTO('z.ai', ['- Error: '.$msg]);
        }
        $code = \array_key_exists('code', $payload) ? $this->num($payload['code']) : null;
        if (true !== ($payload['success'] ?? false) || (null !== $code && 200.0 !== $code)) {
            return new ProviderQuotaSectionDTO('z.ai', ['- Error: z.ai quota query failed.']);
        }

        $lines = [];
        foreach (\is_array($payload['data']['limits'] ?? null) ? $payload['data']['limits'] : [] as $limit) {
            if (!\is_array($limit)) {
                continue;
            }
            $total = $this->num($limit['usage'] ?? null);
            $used = $this->num($limit['currentValue'] ?? null);
            $usedPercent = $this->num($limit['percentage'] ?? null)
                ?? (null !== $total && $total > 0 && null !== $used ? ($used / $total) * 100.0 : null);
            if (null === $usedPercent) {
                continue;
            }
            $type = \is_string($limit['type'] ?? null) ? strtoupper($limit['type']) : '';
            $label = match ($type) {
                'TOKENS_LIMIT' => 'Tokens', 'TIME_LIMIT' => 'Time', default => 'Quota',
            };
            if (null !== $total && null !== $used) {
                $label .= \sprintf(' (%s/%s)', number_format((int) round($used)), number_format((int) round($total)));
            }
            $resetMs = $this->num($limit['nextResetTime'] ?? null);
            $reset = null === $resetMs
                ? ''
                : $this->resetSuffix(($resetMs - (microtime(true) * 1000.0)) / 1000.0);
            $lines[] = \sprintf('- %s: %.0f%% left%s', $label, max(0.0, min(100.0, 100.0 - $usedPercent)), $reset);
        }

        return [] === $lines
            ? new ProviderQuotaSectionDTO('z.ai', ['- Error: z.ai quota response did not include usable window data.'])
            : new ProviderQuotaSectionDTO('z.ai', $lines);
    }

    /** @return array{0: ?int, 1: ?array<string, mixed>} */
    private function read(ResponseInterface $response, string $provider): array
    {
        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Provider quota probe degraded', [
                'component' => 'provider_quota_probe', 'event_type' => 'transport_failed',
                'provider' => $provider, 'exception_class' => $e::class,
            ]);

            return [null, null];
        }
        if ($status < 200 || $status >= 300) {
            return [$status, null];
        }
        try {
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);

            return [$status, $payload];
        } catch (\Throwable $e) {
            $this->logger->warning('Provider quota probe degraded', [
                'component' => 'provider_quota_probe', 'event_type' => 'malformed_response',
                'provider' => $provider, 'exception_class' => $e::class,
            ]);

            return [$status, null];
        }
    }

    private function windowLabel(?float $seconds): string
    {
        if (null === $seconds || $seconds <= 0) {
            return 'primary';
        }

        $sec = (int) round($seconds);
        if (0 === $sec % 3600) {
            $hours = intdiv($sec, 3600);
            if (0 === $hours % 24) {
                return intdiv($hours, 24).'d';
            }

            return $hours.'h';
        }
        if (0 === $sec % 60) {
            return intdiv($sec, 60).'m';
        }

        return $sec.'s';
    }

    private function resetSuffix(mixed $seconds): string
    {
        $parsed = $this->num($seconds);
        if (null === $parsed) {
            return '';
        }
        if ($parsed <= 0) {
            return ', resets now';
        }

        return ', resets in '.$this->formatDuration($parsed);
    }

    private function formatDuration(float $seconds): string
    {
        $total = (int) floor(max(0.0, $seconds));
        if ($total < 60) {
            return $total.'s';
        }

        $minutes = intdiv($total, 60);
        if ($minutes < 60) {
            $remS = $total % 60;

            return $minutes.'m'.($remS > 0 ? $remS.'s' : '');
        }

        $hours = intdiv($minutes, 60);
        $remM = $minutes % 60;

        return $hours.'h'.($remM > 0 ? $remM.'m' : '');
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
}
