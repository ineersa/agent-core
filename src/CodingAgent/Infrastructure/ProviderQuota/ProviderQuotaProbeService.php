<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaReportDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** App-owned `/usage` probe for configured ChatGPT management links and z.ai quota. */
final class ProviderQuotaProbeService
{
    private const string ZAI_QUOTA = 'https://api.z.ai/api/monitor/usage/quota/limit';

    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function probe(): ProviderQuotaReportDTO
    {
        $sections = [];
        foreach ($this->appConfig->catalog?->config()->providers ?? [] as $provider) {
            if ($provider->enabled && 'chatgpt' === $provider->type) {
                $sections[] = new ProviderQuotaSectionDTO('ChatGPT', [
                    '- Plan-backed usage. No numerical quota is available here.',
                    '- Manage usage: '.OAuthConfig::USAGE_URL,
                ]);
            }
        }
        $zaiCfg = $this->appConfig->catalog?->getProvider('zai');
        $zai = null !== $zaiCfg && $zaiCfg->enabled ? $zaiCfg : null;

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

        if (null !== $zaiEarly) {
            $sections[] = $zaiEarly;
        } elseif (null !== $zaiResponse) {
            $sections[] = $this->zai($zaiResponse);
        }

        return new ProviderQuotaReportDTO($sections);
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
        if ($hours < 24) {
            return $hours.'h'.($remM > 0 ? $remM.'m' : '');
        }

        // Multi-day resets: compact d/h/m, omit zero components (24h→1d, 25h→1d1h).
        $days = intdiv($hours, 24);
        $remH = $hours % 24;

        return $days.'d'
            .($remH > 0 ? $remH.'h' : '')
            .($remM > 0 ? $remM.'m' : '');
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
