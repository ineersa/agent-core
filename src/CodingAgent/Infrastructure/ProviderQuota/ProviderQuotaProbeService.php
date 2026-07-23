<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaReportDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaWindowDTO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Probes OpenAI Codex and z.ai quota endpoints with bounded HTTP requests.
 *
 * Credential handling stays in CodingAgent:
 * - Codex: {@see CodexAuthStorage::loadCredentials()} (canonical refresh/lock).
 * - z.ai: effective {@see AiProviderConfig} including `env:NAME` keys.
 *
 * Failures degrade into sanitized section DTOs. Tokens, API keys, and raw
 * response bodies are never returned or logged.
 */
final class ProviderQuotaProbeService implements ProviderQuotaProbeServiceInterface
{
    public const float PROBE_TIMEOUT_SECONDS = 15.0;

    private const string OPENAI_USAGE_ENDPOINT = 'https://chatgpt.com/backend-api/wham/usage';
    private const string ZAI_PROVIDER_ID = 'zai';
    private const string OPENAI_CODEX_PROVIDER_ID = 'openai-codex';
    private const string JWT_PROFILE_CLAIM = 'https://api.openai.com/profile';

    private readonly HttpClientInterface $httpClient;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CodexAuthStorage $codexAuthStorage,
        private readonly HatfieldModelCatalog $modelCatalog,
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        // Dedicated client: no LLM retry decorator so 401/429 stay visible.
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => self::PROBE_TIMEOUT_SECONDS,
            'max_duration' => self::PROBE_TIMEOUT_SECONDS,
        ]);
        $this->logger = $logger ?? new NullLogger();
    }

    public function probe(): ProviderQuotaReportDTO
    {
        // Fire both probes concurrently when credentials resolve, then collect.
        $openaiPending = $this->startOpenAiProbe();
        $zaiPending = $this->startZaiProbe();

        return new ProviderQuotaReportDTO(
            openaiCodex: $this->finishOpenAiProbe($openaiPending),
            zai: $this->finishZaiProbe($zaiPending),
        );
    }

    /**
     * @return array{kind: 'section', section: ProviderQuotaSectionDTO}|array{kind: 'pending', response: ResponseInterface, account: ?string, plan: ?string, accountId: ?string}
     */
    private function startOpenAiProbe(): array
    {
        $provider = $this->modelCatalog->getProvider(self::OPENAI_CODEX_PROVIDER_ID);
        $authKey = $this->resolveCodexAuthKey($provider);

        try {
            $record = $this->codexAuthStorage->loadCredentials($authKey);
        } catch (\Throwable $e) {
            $this->logProbeFailure('openai_codex', 'credential_load_failed', $e);

            return [
                'kind' => 'section',
                'section' => new ProviderQuotaSectionDTO(
                    title: 'OpenAI Codex',
                    error: \sprintf(
                        'Auth token unavailable/expired (run: %s).',
                        CodexOAuthConfig::authCommandHintForProviderKey($authKey),
                    ),
                    configured: true,
                ),
            ];
        }

        if (null === $record) {
            return [
                'kind' => 'section',
                'section' => new ProviderQuotaSectionDTO(
                    title: 'OpenAI Codex',
                    error: \sprintf(
                        'Not configured (run: %s).',
                        CodexOAuthConfig::authCommandHintForProviderKey($authKey),
                    ),
                    configured: false,
                ),
            ];
        }

        $token = trim($record->access);
        if ('' === $token) {
            return [
                'kind' => 'section',
                'section' => new ProviderQuotaSectionDTO(
                    title: 'OpenAI Codex',
                    error: \sprintf(
                        'Auth token unavailable/expired (run: %s).',
                        CodexOAuthConfig::authCommandHintForProviderKey($authKey),
                    ),
                    configured: true,
                ),
            ];
        }

        $jwtMeta = $this->hydrateOpenAiFromJwt($token);
        $accountId = '' !== $record->accountId ? $record->accountId : $jwtMeta['accountId'];

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
        if (null !== $accountId && '' !== $accountId) {
            $headers['ChatGPT-Account-ID'] = $accountId;
        }

        try {
            $response = $this->httpClient->request('GET', self::OPENAI_USAGE_ENDPOINT, [
                'headers' => $headers,
                'timeout' => self::PROBE_TIMEOUT_SECONDS,
                'max_duration' => self::PROBE_TIMEOUT_SECONDS,
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->logProbeFailure('openai_codex', 'request_failed', $e);

            return [
                'kind' => 'section',
                'section' => new ProviderQuotaSectionDTO(
                    title: 'OpenAI Codex',
                    account: $jwtMeta['account'],
                    plan: $jwtMeta['plan'],
                    error: $this->transportErrorMessage('OpenAI', $e),
                    configured: true,
                ),
            ];
        }

        return [
            'kind' => 'pending',
            'response' => $response,
            'account' => $jwtMeta['account'],
            'plan' => $jwtMeta['plan'],
            'accountId' => $accountId,
        ];
    }

    /**
     * @param array{kind: 'section', section: ProviderQuotaSectionDTO}|array{kind: 'pending', response: ResponseInterface, account: ?string, plan: ?string, accountId: ?string} $pending
     */
    private function finishOpenAiProbe(array $pending): ProviderQuotaSectionDTO
    {
        if ('section' === $pending['kind']) {
            return $pending['section'];
        }

        $account = $pending['account'];
        $plan = $pending['plan'];

        try {
            $status = $pending['response']->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logProbeFailure('openai_codex', 'transport_failed', $e);

            return new ProviderQuotaSectionDTO(
                title: 'OpenAI Codex',
                account: $account,
                plan: $plan,
                error: $this->transportErrorMessage('OpenAI', $e),
            );
        }

        if (401 === $status) {
            return new ProviderQuotaSectionDTO(
                title: 'OpenAI Codex',
                account: $account,
                plan: $plan,
                error: \sprintf(
                    'OpenAI auth token expired — run %s.',
                    CodexOAuthConfig::authCommandHintForProviderKey(self::OPENAI_CODEX_PROVIDER_ID),
                ),
            );
        }

        if (429 === $status) {
            $retryHint = $this->retryAfterHint($pending['response']);

            return new ProviderQuotaSectionDTO(
                title: 'OpenAI Codex',
                account: $account,
                plan: $plan,
                note: 'OpenAI usage endpoint is rate-limited'.$retryHint.'.',
            );
        }

        if ($status < 200 || $status >= 300) {
            return new ProviderQuotaSectionDTO(
                title: 'OpenAI Codex',
                account: $account,
                plan: $plan,
                note: \sprintf('OpenAI usage endpoint returned %d.', $status),
            );
        }

        try {
            $payload = $pending['response']->toArray(false);
        } catch (\Throwable $e) {
            $this->logProbeFailure('openai_codex', 'malformed_response', $e);

            return new ProviderQuotaSectionDTO(
                title: 'OpenAI Codex',
                account: $account,
                plan: $plan,
                error: 'OpenAI usage response was malformed.',
            );
        }

        if (!\is_array($payload)) {
            return new ProviderQuotaSectionDTO(
                title: 'OpenAI Codex',
                account: $account,
                plan: $plan,
                error: 'OpenAI usage response was malformed.',
            );
        }

        if (isset($payload['plan_type']) && \is_string($payload['plan_type']) && '' !== $payload['plan_type']) {
            $plan = $payload['plan_type'];
        }
        if (isset($payload['email']) && \is_string($payload['email']) && '' !== $payload['email']) {
            $account = $payload['email'];
        }

        $credits = null;
        $note = null;
        $creditsPayload = $payload['credits'] ?? null;
        if (\is_array($creditsPayload)) {
            if (true === ($creditsPayload['unlimited'] ?? false)) {
                $note = $this->appendNote($note, 'Credits are unlimited.');
            } else {
                $balance = $this->parseFiniteNumber($creditsPayload['balance'] ?? null);
                if (null !== $balance) {
                    $credits = $balance;
                }
            }
        }

        $windows = [];
        $this->addOpenAiRateLimitGroup($windows, $note, 'Codex', $payload['rate_limit'] ?? null);
        $this->addOpenAiRateLimitGroup($windows, $note, 'Code Review', $payload['code_review_rate_limit'] ?? null);

        $additional = $payload['additional_rate_limits'] ?? null;
        if (\is_array($additional)) {
            foreach ($additional as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $limitName = $item['limit_name'] ?? null;
                $meteredFeature = $item['metered_feature'] ?? null;
                $label = \is_string($limitName) && '' !== $limitName
                    ? $limitName
                    : (\is_string($meteredFeature) && '' !== $meteredFeature
                        ? $meteredFeature
                        : 'Additional');
                $this->addOpenAiRateLimitGroup($windows, $note, $label, $item['rate_limit'] ?? null);
            }
        }

        if ([] === $windows) {
            $note = $this->appendNote($note, 'OpenAI response did not include window data.');
        }

        usort($windows, static fn (ProviderQuotaWindowDTO $a, ProviderQuotaWindowDTO $b): int => $a->percentLeft <=> $b->percentLeft);

        return new ProviderQuotaSectionDTO(
            title: 'OpenAI Codex',
            windows: $windows,
            plan: $plan,
            account: $account,
            credits: $credits,
            note: $note,
        );
    }

    /**
     * @return array{kind: 'section', section: ProviderQuotaSectionDTO}|array{kind: 'pending', quota: ResponseInterface, models: ?ResponseInterface, token: string}
     */
    private function startZaiProbe(): array
    {
        $provider = $this->modelCatalog->getProvider(self::ZAI_PROVIDER_ID);
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

        $apiKey = $this->resolveApiKey($provider->apiKey);
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

        try {
            $quotaResponse = $this->requestZai($quotaUrl, $token);
        } catch (TransportExceptionInterface $e) {
            $this->logProbeFailure('zai', 'request_failed', $e);

            return [
                'kind' => 'section',
                'section' => new ProviderQuotaSectionDTO(
                    title: 'z.ai',
                    error: $this->transportErrorMessage('z.ai', $e),
                ),
            ];
        }

        $modelsResponse = null;
        try {
            $modelsResponse = $this->requestZai($modelsUrl, $token);
        } catch (TransportExceptionInterface $e) {
            // Model count is optional enrichment only.
            $this->logProbeFailure('zai', 'models_request_failed', $e);
        }

        return [
            'kind' => 'pending',
            'quota' => $quotaResponse,
            'models' => $modelsResponse,
            'token' => $token,
        ];
    }

    /**
     * @param array{kind: 'section', section: ProviderQuotaSectionDTO}|array{kind: 'pending', quota: ResponseInterface, models: ?ResponseInterface, token: string} $pending
     */
    private function finishZaiProbe(array $pending): ProviderQuotaSectionDTO
    {
        if ('section' === $pending['kind']) {
            return $pending['section'];
        }

        try {
            $status = $pending['quota']->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logProbeFailure('zai', 'transport_failed', $e);

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: $this->transportErrorMessage('z.ai', $e),
            );
        }

        if (401 === $status || 403 === $status) {
            // Retry once with the alternate Authorization form when the first form was rejected.
            $alt = $this->retryZaiWithAlternateAuth($pending['quota'], $pending['token']);
            if (null !== $alt) {
                return $alt;
            }

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: 'z.ai API key rejected — check ai.providers.zai.api_key / ZAI_API_KEY.',
            );
        }

        if (429 === $status) {
            $retryHint = $this->retryAfterHint($pending['quota']);

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                note: 'z.ai quota endpoint is rate-limited'.$retryHint.'.',
                modelCount: $this->readZaiModelCount($pending['models']),
            );
        }

        if ($status < 200 || $status >= 300) {
            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: \sprintf('z.ai quota endpoint returned %d.', $status),
                modelCount: $this->readZaiModelCount($pending['models']),
            );
        }

        try {
            $payload = $pending['quota']->toArray(false);
        } catch (\Throwable $e) {
            $this->logProbeFailure('zai', 'malformed_response', $e);

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: 'z.ai quota response was malformed.',
                modelCount: $this->readZaiModelCount($pending['models']),
            );
        }

        if (!\is_array($payload)) {
            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: 'z.ai quota response was malformed.',
                modelCount: $this->readZaiModelCount($pending['models']),
            );
        }

        $success = true === ($payload['success'] ?? false);
        $code = $this->parseFiniteNumber($payload['code'] ?? null);
        if (!$success || 200.0 !== $code) {
            $msg = $payload['msg'] ?? null;
            $message = \is_string($msg) ? $msg : 'Unknown z.ai error';

            return new ProviderQuotaSectionDTO(
                title: 'z.ai',
                error: \sprintf('z.ai quota query failed (%s): %s', null !== $code ? (string) (int) $code : '?', $message),
                modelCount: $this->readZaiModelCount($pending['models']),
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
            $note = $this->appendNote($note, 'z.ai quota response did not include usable window data.');
        }

        usort($windows, static fn (ProviderQuotaWindowDTO $a, ProviderQuotaWindowDTO $b): int => $a->percentLeft <=> $b->percentLeft);

        return new ProviderQuotaSectionDTO(
            title: 'z.ai',
            windows: $windows,
            modelCount: $this->readZaiModelCount($pending['models']),
            note: $note,
        );
    }

    private function retryZaiWithAlternateAuth(ResponseInterface $first, string $token): ?ProviderQuotaSectionDTO
    {
        // First request already failed auth; try the other Authorization form once.
        unset($first);
        $variants = $this->zaiAuthHeaderVariants($token);
        if (\count($variants) < 2) {
            return null;
        }

        $provider = $this->modelCatalog->getProvider(self::ZAI_PROVIDER_ID);
        if (null === $provider) {
            return null;
        }
        $quotaUrl = $this->zaiQuotaUrl($provider->baseUrl);

        try {
            $response = $this->httpClient->request('GET', $quotaUrl, [
                'headers' => [
                    'Authorization' => $variants[1],
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'hatfield-usage/1.0',
                ],
                'timeout' => self::PROBE_TIMEOUT_SECONDS,
                'max_duration' => self::PROBE_TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logProbeFailure('zai', 'auth_retry_failed', $e);

            return null;
        }

        if (401 === $status || 403 === $status) {
            return null;
        }

        return $this->finishZaiProbe([
            'kind' => 'pending',
            'quota' => $response,
            'models' => null,
            'token' => $token,
        ]);
    }

    private function requestZai(string $url, string $token): ResponseInterface
    {
        $variants = $this->zaiAuthHeaderVariants($token);
        $auth = $variants[0] ?? $token;

        return $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => $auth,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'hatfield-usage/1.0',
            ],
            'timeout' => self::PROBE_TIMEOUT_SECONDS,
            'max_duration' => self::PROBE_TIMEOUT_SECONDS,
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
        $origin = $this->zaiOrigin($baseUrl);

        return $origin.'/api/monitor/usage/quota/limit';
    }

    private function zaiModelsUrl(string $baseUrl): string
    {
        $origin = $this->zaiOrigin($baseUrl);
        // Configured base is typically https://api.z.ai/api/coding/paas/v4 — avoid double path segments.
        if (str_contains($baseUrl, '/api/coding/paas/v4')) {
            return rtrim($baseUrl, '/').'/models';
        }

        return $origin.'/api/coding/paas/v4/models';
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

    private function resolveCodexAuthKey(?AiProviderConfig $provider): string
    {
        if (null !== $provider && null !== $provider->authKey && '' !== trim($provider->authKey)) {
            return trim($provider->authKey);
        }

        return CodexOAuthConfig::PROVIDER_KEY;
    }

    /**
     * Resolve plain or `env:VAR` API keys the same way the provider factory does.
     */
    private function resolveApiKey(?string $apiKey): ?string
    {
        if (null === $apiKey) {
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

        return $apiKey;
    }

    /**
     * @return array{account: ?string, plan: ?string, accountId: ?string}
     */
    private function hydrateOpenAiFromJwt(string $token): array
    {
        $account = null;
        $plan = null;
        $accountId = null;

        $parts = explode('.', $token);
        if (3 !== \count($parts) || '' === $parts[1]) {
            return ['account' => null, 'plan' => null, 'accountId' => null];
        }

        $decoded = $this->base64urlDecode($parts[1]);
        if (null === $decoded) {
            return ['account' => null, 'plan' => null, 'accountId' => null];
        }

        try {
            $payload = json_decode($decoded, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['account' => null, 'plan' => null, 'accountId' => null];
        }

        if (!\is_array($payload)) {
            return ['account' => null, 'plan' => null, 'accountId' => null];
        }

        $profile = $payload[self::JWT_PROFILE_CLAIM] ?? null;
        if (\is_array($profile) && isset($profile['email']) && \is_string($profile['email']) && '' !== $profile['email']) {
            $account = $profile['email'];
        }

        $auth = $payload[CodexOAuthConfig::JWT_CLAIM_PATH] ?? null;
        if (\is_array($auth)) {
            if (isset($auth['chatgpt_plan_type']) && \is_string($auth['chatgpt_plan_type']) && '' !== $auth['chatgpt_plan_type']) {
                $plan = $auth['chatgpt_plan_type'];
            }
            if (isset($auth['chatgpt_account_id']) && \is_string($auth['chatgpt_account_id']) && '' !== $auth['chatgpt_account_id']) {
                $accountId = $auth['chatgpt_account_id'];
            }
        }

        return ['account' => $account, 'plan' => $plan, 'accountId' => $accountId];
    }

    private function base64urlDecode(string $encoded): ?string
    {
        $remainder = \strlen($encoded) % 4;
        if (0 !== $remainder) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return \is_string($decoded) ? $decoded : null;
    }

    /**
     * @param list<ProviderQuotaWindowDTO> $windows
     */
    private function addOpenAiRateLimitGroup(array &$windows, ?string &$note, string $groupLabel, mixed $group): void
    {
        if (!\is_array($group)) {
            return;
        }
        if (false === ($group['allowed'] ?? null)) {
            $note = $this->appendNote($note, $groupLabel.' currently blocked.');
        }
        if (true === ($group['limit_reached'] ?? null)) {
            $note = $this->appendNote($note, $groupLabel.' limit reached.');
        }

        $this->maybeAddOpenAiWindow($windows, $groupLabel, 'primary', $group['primary_window'] ?? null);
        $this->maybeAddOpenAiWindow($windows, $groupLabel, 'secondary', $group['secondary_window'] ?? null);
    }

    /**
     * @param list<ProviderQuotaWindowDTO> $windows
     */
    private function maybeAddOpenAiWindow(array &$windows, string $groupLabel, string $windowLabel, mixed $window): void
    {
        if (!\is_array($window)) {
            return;
        }
        $usedPercent = $this->parseFiniteNumber($window['used_percent'] ?? null);
        if (null === $usedPercent) {
            return;
        }

        $windowSeconds = $this->parseFiniteNumber($window['limit_window_seconds'] ?? null);
        $roundedWindowSeconds = null !== $windowSeconds && $windowSeconds > 0 ? (int) round($windowSeconds) : null;
        $labelSuffix = null !== $roundedWindowSeconds
            ? $this->windowLabelFromSeconds($roundedWindowSeconds)
            : $windowLabel;

        $resetFromDuration = $this->countdownFromSeconds($window['reset_after_seconds'] ?? null);
        $resetAtSeconds = $this->parseFiniteNumber($window['reset_at'] ?? null);
        $resetFromTimestamp = null === $resetAtSeconds
            ? null
            : $this->countdownFromSeconds($resetAtSeconds - microtime(true));

        $windows[] = new ProviderQuotaWindowDTO(
            label: \sprintf('%s (%s)', $groupLabel, $labelSuffix),
            percentLeft: $this->clampPercent(100.0 - $usedPercent),
            resetDescription: $resetFromDuration ?? $resetFromTimestamp,
        );
    }

    private function parseZaiQuotaWindow(mixed $limit): ?ProviderQuotaWindowDTO
    {
        if (!\is_array($limit)) {
            return null;
        }

        $typeRaw = $limit['type'] ?? null;
        $limitType = \is_string($typeRaw) ? strtoupper($typeRaw) : '';
        $total = $this->parseFiniteNumber($limit['usage'] ?? null);
        $used = $this->parseFiniteNumber($limit['currentValue'] ?? null);
        $reportedPercent = $this->parseFiniteNumber($limit['percentage'] ?? null);

        $usedPercent = $reportedPercent
            ?? (null !== $total && $total > 0 && null !== $used ? ($used / $total) * 100.0 : null);
        if (null === $usedPercent) {
            return null;
        }

        $label = 'Quota';
        if ('TOKENS_LIMIT' === $limitType) {
            $label = 'Tokens';
        } elseif ('TIME_LIMIT' === $limitType) {
            $label = 'MCP searches';
        }
        if (null !== $total && null !== $used) {
            $label .= \sprintf(' (%s/%s)', number_format((int) round($used)), number_format((int) round($total)));
        }

        $nextResetTime = $this->parseFiniteNumber($limit['nextResetTime'] ?? null);
        $resetDescription = null;
        if (null !== $nextResetTime) {
            $diffMs = $nextResetTime - (microtime(true) * 1000.0);
            $resetDescription = $diffMs <= 0 ? 'now' : 'in '.$this->fmtDurationMs($diffMs);
        }

        return new ProviderQuotaWindowDTO(
            label: $label,
            percentLeft: $this->clampPercent(100.0 - $usedPercent),
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
            $this->logProbeFailure('zai', 'models_parse_failed', $e);

            return null;
        }
    }

    private function windowLabelFromSeconds(int $seconds): string
    {
        if (0 === $seconds % 3600) {
            $hours = intdiv($seconds, 3600);
            if (0 === $hours % 24) {
                $days = intdiv($hours, 24);

                return $days.'d';
            }

            return $hours.'h';
        }
        if (0 === $seconds % 60) {
            return intdiv($seconds, 60).'m';
        }

        return $seconds.'s';
    }

    private function countdownFromSeconds(mixed $seconds): ?string
    {
        $parsed = $this->parseFiniteNumber($seconds);
        if (null === $parsed) {
            return null;
        }
        if ($parsed <= 0) {
            return 'now';
        }

        return 'in '.$this->fmtDurationMs($parsed * 1000.0);
    }

    private function fmtDurationMs(float $ms): string
    {
        $seconds = (int) floor(max(0.0, $ms) / 1000.0);
        if ($seconds < 60) {
            return $seconds.'s';
        }
        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;
        if ($minutes < 60) {
            return $minutes.'m'.($remainingSeconds > 0 ? $remainingSeconds.'s' : '');
        }
        $days = intdiv($minutes, 24 * 60);
        $minutesAfterDays = $minutes - ($days * 24 * 60);
        $hours = intdiv($minutesAfterDays, 60);
        $remainingMinutes = $minutesAfterDays % 60;
        if ($days > 0) {
            return $days.'d'
                .($hours > 0 ? $hours.'h' : '')
                .($remainingMinutes > 0 ? $remainingMinutes.'m' : '');
        }

        return $hours.'h'.($remainingMinutes > 0 ? $remainingMinutes.'m' : '');
    }

    private function clampPercent(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }

    private function parseFiniteNumber(mixed $value): ?float
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

    private function appendNote(?string $existing, string $addition): string
    {
        if (null === $existing || '' === $existing) {
            return $addition;
        }

        return $existing.' '.$addition;
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

        return ' (retry in '.$this->fmtDurationMs($seconds * 1000.0).')';
    }

    private function transportErrorMessage(string $providerLabel, TransportExceptionInterface $e): string
    {
        $message = $e->getMessage();
        if (str_contains(strtolower($message), 'timeout') || str_contains(strtolower($message), 'timed out')) {
            return $providerLabel.' usage probe timed out.';
        }

        // Keep the message short and free of response bodies / credentials.
        return $providerLabel.' usage probe failed.';
    }

    private function logProbeFailure(string $provider, string $eventType, \Throwable $e): void
    {
        $this->logger->warning('Provider quota probe degraded', [
            'component' => 'provider_quota_probe',
            'event_type' => $eventType,
            'provider' => $provider,
            'exception_class' => $e::class,
            // Message only — never dump response bodies or secrets.
            'error' => $e->getMessage(),
        ]);
    }
}
