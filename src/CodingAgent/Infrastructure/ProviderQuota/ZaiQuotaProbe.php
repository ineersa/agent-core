<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaWindowDTO;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * z.ai quota + optional models-list probe.
 *
 * Starts concurrent quota/models requests, then finishes parsing. On 401/403
 * the alternate Authorization form is retried while preserving any models
 * response already received from the first attempt.
 */
final class ZaiQuotaProbe
{
    private const string PROVIDER_ID = 'zai';

    public function __construct(
        private readonly ?HatfieldModelCatalog $modelCatalog,
        private readonly HttpClientInterface $httpClient,
        private readonly ProviderQuotaProbeFormatting $format,
        private readonly LoggerInterface $logger,
        private readonly float $timeoutSeconds,
    ) {
    }

    /**
     * @return array{kind: 'section', section: ProviderQuotaSectionDTO}|array{kind: 'pending', quota: ResponseInterface, models: ?ResponseInterface, token: string, modelsUrl: string, usedAuth: string}
     */
    public function start(): array
    {
        $provider = $this->modelCatalog?->getProvider(self::PROVIDER_ID);
        if (null === $provider || !$provider->enabled) {
            return [
                'kind' => 'section',
                'section' => new ProviderQuotaSectionDTO(
                    title: 'z.ai',
                    error: 'Not configured (enable ai.providers.zai and set api_key, e.g. env:ZAI_API_KEY).',
                    configured: false,
                ),
            ];
        }

        $apiKey = $this->format->resolveApiKey($provider->apiKey);
        if (null === $apiKey || '' === trim($apiKey)) {
            $hint = null !== $provider->apiKey && str_starts_with($provider->apiKey, 'env:')
                ? \sprintf('Configured, but %s could not be resolved.', substr($provider->apiKey, 4))
                : 'Configured, but API key could not be resolved.';

            return [
                'kind' => 'section',
                'section' => new ProviderQuotaSectionDTO(
                    title: 'z.ai',
                    error: $hint,
                    configured: true,
                ),
            ];
        }

        $token = trim($apiKey);
        $quotaUrl = $this->zaiQuotaUrl($provider->baseUrl);
        $modelsUrl = $this->zaiModelsUrl($provider->baseUrl);
        $variants = $this->zaiAuthHeaderVariants($token);
        $usedAuth = $variants[0] ?? $token;

        // Symfony HttpClient is lazy: transport errors surface on response access.
        $quotaResponse = $this->requestZai($quotaUrl, $usedAuth);

        $modelsResponse = null;
        try {
            $modelsResponse = $this->requestZai($modelsUrl, $usedAuth);
        } catch (\Throwable $e) {
            // Model count is optional enrichment only.
            $this->logFailure('models_request_failed', $e);
        }

        return [
            'kind' => 'pending',
            'quota' => $quotaResponse,
            'models' => $modelsResponse,
            'token' => $token,
            'modelsUrl' => $modelsUrl,
            'usedAuth' => $usedAuth,
        ];
    }

    /**
     * @param array{kind: 'section', section: ProviderQuotaSectionDTO}|array{kind: 'pending', quota: ResponseInterface, models: ?ResponseInterface, token: string, modelsUrl: string, usedAuth: string} $pending
     */
    public function finish(array $pending): ProviderQuotaSectionDTO
    {
        if ('section' === $pending['kind']) {
            return $pending['section'];
        }

        try {
            $status = $pending['quota']->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logFailure('transport_failed', $e);

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: $this->format->transportErrorMessage('z.ai', $e),
            );
        }

        if (401 === $status || 403 === $status) {
            $alt = $this->retryWithAlternateAuth($pending);
            if (null !== $alt) {
                return $alt;
            }

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: 'z.ai API key rejected — check ai.providers.zai.api_key / ZAI_API_KEY.',
                // Preserve models from the first attempt when available.
                modelCount: $this->readZaiModelCount($pending['models']),
            );
        }

        return $this->parseSuccessfulQuota(
            $pending['quota'],
            $this->readZaiModelCount($pending['models']),
            $status,
        );
    }

    /**
     * @param array{kind: 'pending', quota: ResponseInterface, models: ?ResponseInterface, token: string, modelsUrl: string, usedAuth: string} $pending
     */
    private function retryWithAlternateAuth(array $pending): ?ProviderQuotaSectionDTO
    {
        $variants = $this->zaiAuthHeaderVariants($pending['token']);
        if (\count($variants) < 2) {
            return null;
        }

        $alternateAuth = null;
        foreach ($variants as $variant) {
            if ($variant !== $pending['usedAuth']) {
                $alternateAuth = $variant;
                break;
            }
        }
        if (null === $alternateAuth) {
            return null;
        }

        $provider = $this->modelCatalog?->getProvider(self::PROVIDER_ID);
        if (null === $provider) {
            return null;
        }
        $quotaUrl = $this->zaiQuotaUrl($provider->baseUrl);

        // Keep any models response already received under the first auth form.
        $modelsResponse = $pending['models'];
        $modelCount = $this->readZaiModelCount($modelsResponse);

        // If models also failed auth (or never arrived), re-request with the alternate form.
        if (null === $modelCount) {
            try {
                $modelsResponse = $this->requestZai($pending['modelsUrl'], $alternateAuth);
                $modelCount = $this->readZaiModelCount($modelsResponse);
            } catch (\Throwable $e) {
                $this->logFailure('models_auth_retry_failed', $e);
            }
        }

        try {
            $response = $this->requestZai($quotaUrl, $alternateAuth);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logFailure('auth_retry_failed', $e);

            return null;
        }

        if (401 === $status || 403 === $status) {
            return null;
        }

        return $this->parseSuccessfulQuota($response, $modelCount, $status);
    }

    private function parseSuccessfulQuota(ResponseInterface $quota, ?int $modelCount, int $status): ProviderQuotaSectionDTO
    {
        if (429 === $status) {
            $retryHint = $this->retryAfterHint($quota);

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                note: 'z.ai quota endpoint is rate-limited'.$retryHint.'.',
                modelCount: $modelCount,
            );
        }

        if ($status < 200 || $status >= 300) {
            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: \sprintf('z.ai quota endpoint returned %d.', $status),
                modelCount: $modelCount,
            );
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = $quota->toArray(false);
        } catch (\Throwable $e) {
            $this->logFailure('malformed_response', $e);

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: 'z.ai quota response was malformed.',
                modelCount: $modelCount,
            );
        }

        $success = true === ($payload['success'] ?? false);
        $code = $this->format->parseFiniteNumber($payload['code'] ?? null);
        if (!$success || 200.0 !== $code) {
            $msg = $payload['msg'] ?? null;
            // Bound provider-supplied message; never dump multi-line bodies.
            $message = \is_string($msg) ? trim(explode("\n", $msg, 2)[0]) : 'Unknown z.ai error';
            if (mb_strlen($message) > 120) {
                $message = mb_substr($message, 0, 120).'…';
            }

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: \sprintf('z.ai quota query failed (%s): %s', null !== $code ? (string) (int) $code : '?', $message),
                modelCount: $modelCount,
            );
        }

        $windows = [];
        $note = null;
        $limitsRaw = $payload['data']['limits'] ?? null;
        $limits = \is_array($limitsRaw) ? $limitsRaw : [];
        foreach ($limits as $limit) {
            $window = $this->parseZaiQuotaWindow($limit);
            if (null !== $window) {
                $windows[] = $window;
            }
        }
        if ([] === $windows) {
            $note = $this->format->appendNote($note, 'z.ai quota response did not include usable window data.');
        }

        usort($windows, static fn (ProviderQuotaWindowDTO $a, ProviderQuotaWindowDTO $b): int => $a->percentLeft <=> $b->percentLeft);

        return new ProviderQuotaSectionDTO(
            title: 'z.ai',
            windows: $windows,
            modelCount: $modelCount,
            note: $note,
        );
    }

    private function requestZai(string $url, string $authorization): ResponseInterface
    {
        return $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => $authorization,
                'Accept' => 'application/json',
                'User-Agent' => 'hatfield-usage/1.0',
            ],
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
        ]);
    }

    /**
     * @return list<string>
     */
    private function zaiAuthHeaderVariants(string $token): array
    {
        $trimmed = trim($token);
        if ('' === $trimmed) {
            return [];
        }
        if (1 === preg_match('/^bearer\s+/i', $trimmed)) {
            return [$trimmed];
        }

        return [$trimmed, 'Bearer '.$trimmed];
    }

    private function zaiQuotaUrl(string $baseUrl): string
    {
        return $this->zaiOrigin($baseUrl).'/api/monitor/usage/quota/limit';
    }

    private function zaiModelsUrl(string $baseUrl): string
    {
        // Configured base is typically https://api.z.ai/api/coding/paas/v4 — avoid double path segments.
        if (str_contains($baseUrl, '/api/coding/paas/v4')) {
            return rtrim($baseUrl, '/').'/models';
        }

        return $this->zaiOrigin($baseUrl).'/api/coding/paas/v4/models';
    }

    private function zaiOrigin(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'https://api.z.ai';
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    private function parseZaiQuotaWindow(mixed $limit): ?ProviderQuotaWindowDTO
    {
        if (!\is_array($limit)) {
            return null;
        }

        $typeRaw = $limit['type'] ?? null;
        $limitType = \is_string($typeRaw) ? strtoupper($typeRaw) : '';
        $total = $this->format->parseFiniteNumber($limit['usage'] ?? null);
        $used = $this->format->parseFiniteNumber($limit['currentValue'] ?? null);
        $reportedPercent = $this->format->parseFiniteNumber($limit['percentage'] ?? null);

        $usedPercent = $reportedPercent
            ?? (null !== $total && $total > 0 && null !== $used ? ($used / $total) * 100.0 : null);
        if (null === $usedPercent) {
            return null;
        }

        // Generic accurate labels — response does not name product features for TIME_LIMIT.
        $label = 'Quota';
        if ('TOKENS_LIMIT' === $limitType) {
            $label = 'Tokens';
        } elseif ('TIME_LIMIT' === $limitType) {
            $label = 'Time';
        }
        if (null !== $total && null !== $used) {
            $label .= \sprintf(' (%s/%s)', number_format((int) round($used)), number_format((int) round($total)));
        }

        $nextResetTime = $this->format->parseFiniteNumber($limit['nextResetTime'] ?? null);
        $resetDescription = null;
        if (null !== $nextResetTime) {
            $diffMs = $nextResetTime - (microtime(true) * 1000.0);
            $resetDescription = $diffMs <= 0 ? 'now' : 'in '.$this->format->fmtDurationMs($diffMs);
        }

        return new ProviderQuotaWindowDTO(
            label: $label,
            percentLeft: $this->format->clampPercent(100.0 - $usedPercent),
            resetDescription: $resetDescription,
        );
    }

    private function readZaiModelCount(?ResponseInterface $response): ?int
    {
        if (null === $response) {
            return null;
        }
        try {
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return null;
            }
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
            $data = $payload['data'] ?? null;

            return \is_array($data) ? \count($data) : null;
        } catch (\Throwable $e) {
            $this->logFailure('models_parse_failed', $e);

            return null;
        }
    }

    private function retryAfterHint(ResponseInterface $response): string
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (\Throwable) {
            return '';
        }
        $raw = $headers['retry-after'][0] ?? null;
        if (!\is_string($raw) || !is_numeric($raw)) {
            return '';
        }
        $seconds = (int) $raw;
        if ($seconds < 0) {
            $seconds = 0;
        }

        return ' (retry in '.$this->format->fmtDurationMs($seconds * 1000.0).')';
    }

    private function logFailure(string $eventType, \Throwable $e): void
    {
        $this->logger->warning('Provider quota probe degraded', [
            'component' => 'provider_quota_probe',
            'event_type' => $eventType,
            'provider' => self::PROVIDER_ID,
            'exception_class' => $e::class,
            // Never log raw exception messages — they may contain response snippets.
            'reason_code' => $eventType,
        ]);
    }
}
