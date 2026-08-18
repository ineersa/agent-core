<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Providers;

use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetch models.dev api.json, filter to catalog providers, write user cache.
 * Soft-fails on network/HTTP/JSON errors (exit 0, keep old cache).
 * Sole product network I/O for models.dev.
 */
#[AsCommand(name: 'providers:update', description: 'Refresh models.dev metadata cache for the AI catalog')]
final class ProvidersUpdateCommand
{
    private const API_URL = 'https://models.dev/api.json';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiCatalog $aiCatalog,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $cachePath = $this->aiCatalog->cachePath();

        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $io->warning(\sprintf('models.dev returned HTTP %d; kept existing cache.', $status));

                return Command::SUCCESS;
            }

            try {
                $decoded = json_decode($response->getContent(false), true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $io->warning(\sprintf('models.dev returned invalid JSON (%s); kept existing cache.', $e->getMessage()));

                return Command::SUCCESS;
            }

            if (!\is_array($decoded)) {
                $io->warning('models.dev payload was not an object; kept existing cache.');

                return Command::SUCCESS;
            }

            $filtered = $this->aiCatalog->filterUpstreamProviders($decoded);
            $encoded = json_encode($filtered, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n";
            AtomicFileWriter::write($cachePath, $encoded, fileMode: 0o600, directoryMode: 0o700);

            $io->success(\sprintf(
                'Wrote filtered models.dev data (%d providers, %d bytes) to %s.',
                \count($filtered),
                \strlen($encoded),
                $cachePath,
            ));
            $this->printDiscovery($io, $filtered);
        } catch (\Throwable $e) {
            $io->warning(\sprintf('models.dev fetch failed (%s); kept existing cache.', $e->getMessage()));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $filtered
     */
    private function printDiscovery(SymfonyStyle $io, array $filtered): void
    {
        $hints = $this->aiCatalog->discoveryHints($filtered);
        if ([] === $hints) {
            $io->writeln('No upstream discovery hints (catalog already covers mapped upstream model ids).');

            return;
        }

        $io->section('Upstream discovery hints (not in config/ai-catalog.yaml)');
        foreach ($hints as $providerId => $modelIds) {
            $io->writeln(\sprintf('  %s: %s', $providerId, implode(', ', $modelIds)));
        }
        $io->writeln('Add ids to config/ai-catalog.yaml to ship them; models.dev never auto-adds.');
    }
}
