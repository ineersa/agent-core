<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SemanticIndexJobHandler implements ExtensionAgentJobHandlerInterface
{
    public const string HANDLER_ID = 'observational_memory.semantic_index';

    public function __construct(private LoggerInterface $logger, private ?HttpClientInterface $http = null)
    {
    }

    public static function schedule(ExtensionApiInterface $api, OmSettings $settings, string $runId): void
    {
        if (null === $settings->semantic) {
            return;
        }
        $api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
            handlerId: self::HANDLER_ID,
            payload: ['run_id' => $runId],
            jobId: bin2hex(random_bytes(16)),
            correlationId: $runId,
        ));
    }

    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
    {
        $settings = OmSettings::fromApi($api);
        if (null === $settings->semantic) {
            return;
        }
        $runId = $payload['run_id'] ?? null;
        if (!\is_string($runId) || '' === $runId) {
            throw new \InvalidArgumentException('Semantic index job requires run_id.');
        }
        $path = OmPaths::fromSettings($settings, $api->getCwd())->databasePath;
        $connection = OmDatabaseFactory::connectAndMigrate($path, $this->logger);
        try {
            $index = new SemanticIndexService($connection, $path, $settings->semantic, new SemanticApiClient($settings->semantic, $this->http), $this->logger, $runId);
            if (!$index->synchronize()) {
                self::schedule($api, $settings, $runId);
            }
        } catch (\Throwable $error) {
            // HTTP exceptions can contain response bodies. Log classification,
            // not exception messages or objects containing memory text/vectors.
            $this->logger->error('om.semantic.index_failed', [
                'run_id' => $runId, 'session_id' => $runId,
                'component' => 'observational_memory', 'event_type' => 'om.semantic.index_failed',
                'job_id' => $jobId, 'exception_class' => $error::class,
                'failure_code' => $error instanceof SemanticSearchException ? $error->failureCode : 'index_failed',
            ]);
            throw new \RuntimeException('OM semantic indexing failed; the derived index remains unavailable.');
        } finally {
            $connection->close();
        }
    }
}
