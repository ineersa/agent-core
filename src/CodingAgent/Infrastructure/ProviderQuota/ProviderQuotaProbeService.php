<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaReportDTO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Orchestrates OpenAI Codex and z.ai quota probes with concurrent HTTP start.
 *
 * Credential handling stays in CodingAgent:
 * - Codex: {@see CodexAuthStorage::loadCredentials()} (canonical refresh/lock).
 * - z.ai: effective provider config including `env:NAME` keys.
 *
 * Failures degrade into sanitized section DTOs. Tokens, API keys, and raw
 * response bodies are never returned or logged.
 */
final class ProviderQuotaProbeService implements ProviderQuotaProbeServiceInterface
{
    public const float PROBE_TIMEOUT_SECONDS = 15.0;

    private readonly OpenAiCodexQuotaProbe $openAiProbe;
    private readonly ZaiQuotaProbe $zaiProbe;

    public function __construct(
        CodexAuthStorage $codexAuthStorage,
        ?HatfieldModelCatalog $modelCatalog = null,
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        ?ProviderQuotaProbeFormatting $formatting = null,
    ) {
        // Dedicated client: no LLM retry decorator so 401/429 stay visible.
        $client = $httpClient ?? HttpClient::create([
            'timeout' => self::PROBE_TIMEOUT_SECONDS,
            'max_duration' => self::PROBE_TIMEOUT_SECONDS,
        ]);
        $logger ??= new NullLogger();
        $formatting ??= new ProviderQuotaProbeFormatting();

        $this->openAiProbe = new OpenAiCodexQuotaProbe(
            codexAuthStorage: $codexAuthStorage,
            modelCatalog: $modelCatalog,
            httpClient: $client,
            format: $formatting,
            logger: $logger,
            timeoutSeconds: self::PROBE_TIMEOUT_SECONDS,
        );
        $this->zaiProbe = new ZaiQuotaProbe(
            modelCatalog: $modelCatalog,
            httpClient: $client,
            format: $formatting,
            logger: $logger,
            timeoutSeconds: self::PROBE_TIMEOUT_SECONDS,
        );
    }

    /**
     * Build a probe service from effective AppConfig so missing ai config
     * does not break container boot (catalog becomes null).
     */
    public static function fromAppConfig(
        CodexAuthStorage $codexAuthStorage,
        AppConfig $appConfig,
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            codexAuthStorage: $codexAuthStorage,
            modelCatalog: $appConfig->catalog,
            httpClient: $httpClient,
            logger: $logger,
        );
    }

    public function probe(): ProviderQuotaReportDTO
    {
        // Fire both probes concurrently when credentials resolve, then collect.
        $openaiPending = $this->openAiProbe->start();
        $zaiPending = $this->zaiProbe->start();

        return new ProviderQuotaReportDTO(
            openaiCodex: $this->openAiProbe->finish($openaiPending),
            zai: $this->zaiProbe->finish($zaiPending),
        );
    }
}
