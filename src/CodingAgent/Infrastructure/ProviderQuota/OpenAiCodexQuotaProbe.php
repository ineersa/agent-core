<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaWindowDTO;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OpenAI Codex WHAM usage probe.
 *
 * Starts the HTTP request first (for concurrent collection with z.ai), then
 * finishes parsing after both probes have been dispatched.
 */
final class OpenAiCodexQuotaProbe
{
    private const string USAGE_ENDPOINT = 'https://chatgpt.com/backend-api/wham/usage';
    private const string JWT_PROFILE_CLAIM = 'https://api.openai.com/profile';

    public function __construct(
        private readonly CodexAuthStorage $codexAuthStorage,
        private readonly ?HatfieldModelCatalog $modelCatalog,
        private readonly HttpClientInterface $httpClient,
        private readonly ProviderQuotaProbeFormatting $format,
        private readonly LoggerInterface $logger,
        private readonly float $timeoutSeconds,
    ) {
    }

    /**
     * @return array{kind: 'section', section: ProviderQuotaSectionDTO}|array{kind: 'pending', response: ResponseInterface, account: ?string, plan: ?string, authKey: string}
     */
    public function start(): array
    {
        $provider = $this->modelCatalog?->getProvider(CodexOAuthConfig::PROVIDER_KEY);
        $authKey = $this->resolveCodexAuthKey($provider);

        try {
            $record = $this->codexAuthStorage->loadCredentials($authKey);
        } catch (\Throwable $e) {
            $this->logFailure('credential_load_failed', $e);

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

        // Symfony HttpClient is lazy: TransportException is thrown when the
        // response is consumed (getStatusCode/toArray), not at request().
        $response = $this->httpClient->request('GET', self::USAGE_ENDPOINT, [
            'headers' => $headers,
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
        ]);

        return [
            'kind' => 'pending',
            'response' => $response,
            'account' => $jwtMeta['account'],
            'plan' => $jwtMeta['plan'],
            // Carry the resolved auth key so 401 remediation can mention custom profiles.
            'authKey' => $authKey,
        ];
    }

    /**
     * @param array{kind: 'section', section: ProviderQuotaSectionDTO}|array{kind: 'pending', response: ResponseInterface, account: ?string, plan: ?string, authKey: string} $pending
     */
    public function finish(array $pending): ProviderQuotaSectionDTO
    {
        if ('section' === $pending['kind']) {
            return $pending['section'];
        }

        $account = $pending['account'];
        $plan = $pending['plan'];
        $authKey = $pending['authKey'];

        try {
            $status = $pending['response']->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logFailure('transport_failed', $e);

            return new ProviderQuotaSectionDTO(
                title: 'OpenAI Codex',
                account: $account,
                plan: $plan,
                error: $this->format->transportErrorMessage('OpenAI', $e),
            );
        }

        if (401 === $status) {
            return new ProviderQuotaSectionDTO(
                title: 'OpenAI Codex',
                account: $account,
                plan: $plan,
                error: \sprintf(
                    'OpenAI auth token expired — run %s.',
                    CodexOAuthConfig::authCommandHintForProviderKey($authKey),
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
            /** @var array<string, mixed> $payload */
            $payload = $pending['response']->toArray(false);
        } catch (\Throwable $e) {
            $this->logFailure('malformed_response', $e);

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
                $note = $this->format->appendNote($note, 'Credits are unlimited.');
            } else {
                $balance = $this->format->parseFiniteNumber($creditsPayload['balance'] ?? null);
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
            $note = $this->format->appendNote($note, 'OpenAI response did not include window data.');
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

    private function resolveCodexAuthKey(?AiProviderConfig $provider): string
    {
        if (null !== $provider && null !== $provider->authKey && '' !== trim($provider->authKey)) {
            return trim($provider->authKey);
        }

        return CodexOAuthConfig::PROVIDER_KEY;
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
            $note = $this->format->appendNote($note, $groupLabel.' currently blocked.');
        }
        if (true === ($group['limit_reached'] ?? null)) {
            $note = $this->format->appendNote($note, $groupLabel.' limit reached.');
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
        $usedPercent = $this->format->parseFiniteNumber($window['used_percent'] ?? null);
        if (null === $usedPercent) {
            return;
        }

        $windowSeconds = $this->format->parseFiniteNumber($window['limit_window_seconds'] ?? null);
        $roundedWindowSeconds = null !== $windowSeconds && $windowSeconds > 0 ? (int) round($windowSeconds) : null;
        $labelSuffix = null !== $roundedWindowSeconds
            ? $this->format->windowLabelFromSeconds($roundedWindowSeconds)
            : $windowLabel;

        $resetFromDuration = $this->format->countdownFromSeconds($window['reset_after_seconds'] ?? null);
        $resetAtSeconds = $this->format->parseFiniteNumber($window['reset_at'] ?? null);
        $resetFromTimestamp = null === $resetAtSeconds
            ? null
            : $this->format->countdownFromSeconds($resetAtSeconds - microtime(true));

        $windows[] = new ProviderQuotaWindowDTO(
            label: \sprintf('%s (%s)', $groupLabel, $labelSuffix),
            percentLeft: $this->format->clampPercent(100.0 - $usedPercent),
            resetDescription: $resetFromDuration ?? $resetFromTimestamp,
        );
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
            'provider' => CodexOAuthConfig::PROVIDER_KEY,
            'exception_class' => $e::class,
            // Never log raw exception messages — they may contain response snippets.
            'reason_code' => $eventType,
        ]);
    }
}
