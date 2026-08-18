<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Providers;

use Ineersa\CodingAgent\Config\Ai\AiCatalogMerge;
use Ineersa\CodingAgent\Config\Ai\ModelsDevCache;
use Ineersa\CodingAgent\Config\Ai\ModelsDevMetadataFilter;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetch models.dev api.json (ETag conditional GET), filter to catalog providers,
 * write ~/.hatfield/cache/models-dev.json. Never hard-fails on network errors.
 *
 * --refresh-snapshot writes config/models-dev.snapshot.json (maintainer path).
 * This is the ONLY product network I/O for models.dev (plus optional prompt in providers:setup).
 */
#[AsCommand(name: 'providers:update', description: 'Refresh models.dev metadata cache for the AI catalog')]
final class ProvidersUpdateCommand
{
    private const API_URL = 'https://models.dev/api.json';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsPathResolver $pathResolver,
        private readonly AppResourceLocator $resources,
        private readonly AiCatalogMerge $aiCatalogMerge = new AiCatalogMerge(),
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Write filtered JSON to config/models-dev.snapshot.json instead of the user cache')]
        bool $refreshSnapshot = false,
    ): int {
        $cache = new ModelsDevCache(
            homeDir: $this->pathResolver->getHomeDir(),
            snapshotPath: $this->resources->getModelsDevSnapshotPath(),
        );

        $targetPath = $refreshSnapshot ? $cache->snapshotPath() : $cache->cachePath();
        $storedEtag = $refreshSnapshot ? null : $cache->readStoredEtag();

        try {
            $headers = ['Accept' => 'application/json'];
            if (null !== $storedEtag) {
                $headers['If-None-Match'] = $storedEtag;
            }

            $response = $this->httpClient->request('GET', self::API_URL, [
                'headers' => $headers,
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();

            if (304 === $status) {
                $io->success('models.dev unchanged (HTTP 304); kept existing cache.');
                $this->printDiscovery($io, $cache->loadFilteredProviders());

                return Command::SUCCESS;
            }

            if ($status < 200 || $status >= 300) {
                $io->warning(\sprintf('models.dev returned HTTP %d; kept existing cache/snapshot.', $status));

                return Command::SUCCESS;
            }

            $rawBody = $response->getContent(false);
            try {
                $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $io->warning(\sprintf('models.dev returned invalid JSON (%s); kept existing cache/snapshot.', $e->getMessage()));

                return Command::SUCCESS;
            }

            if (!\is_array($decoded)) {
                $io->warning('models.dev payload was not an object; kept existing cache/snapshot.');

                return Command::SUCCESS;
            }

            $filtered = ModelsDevMetadataFilter::filterProviders($decoded);
            $encoded = json_encode($filtered, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n";
            AtomicFileWriter::write($targetPath, $encoded, fileMode: 0o600, directoryMode: 0o700);

            if (!$refreshSnapshot) {
                $etag = $response->getHeaders(false)['etag'][0] ?? null;
                if (\is_string($etag) && '' !== $etag) {
                    AtomicFileWriter::write($cache->etagPath(), $etag."\n", fileMode: 0o600, directoryMode: 0o700);
                }
            }

            $io->success(\sprintf(
                'Wrote filtered models.dev data (%d providers, %d bytes) to %s.',
                \count($filtered),
                \strlen($encoded),
                $targetPath,
            ));
            $this->printDiscovery($io, $filtered);
        } catch (\Throwable $e) {
            $io->warning(\sprintf('models.dev fetch failed (%s); kept existing cache/snapshot.', $e->getMessage()));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $filtered
     */
    private function printDiscovery(SymfonyStyle $io, array $filtered): void
    {
        $hints = $this->aiCatalogMerge->discoveryHints($this->resources->getAiCatalogPath(), $filtered);
        if ([] === $hints) {
            $io->writeln('No upstream discovery hints (catalog already covers all upstream model ids for mapped providers).');

            return;
        }

        $io->section('Upstream discovery hints (not in config/ai-catalog.yaml)');
        foreach ($hints as $providerId => $modelIds) {
            $io->writeln(\sprintf('  %s: %s', $providerId, implode(', ', $modelIds)));
        }
        $io->writeln('Add ids to config/ai-catalog.yaml to ship them; models.dev never auto-adds.');
    }
}
