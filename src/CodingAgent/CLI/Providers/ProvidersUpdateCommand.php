<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Providers;

use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Rebase ~/.hatfield/ai-catalog.yaml onto the bundled default, then sync
 * allowlisted model metadata from models.dev. Soft-fails offline (exit 0).
 * Sole product network I/O for models.dev. Connection fields never come from upstream.
 */
#[AsCommand(name: 'providers:update', description: 'Refresh the user AI catalog from the bundled default and models.dev')]
final class ProvidersUpdateCommand
{
    private const API_URL = 'https://models.dev/api.json';

    /**
     * Hatfield provider id → models.dev provider id.
     *
     * @var array<string, string>
     */
    private const PROVIDER_ID_MAP = [
        'zai' => 'zai',
        'deepseek' => 'deepseek',
        'openai-codex' => 'openai',
        'grok-cli' => 'xai',
    ];

    /** @var list<string> */
    private const METADATA_KEYS = [
        'context_window',
        'max_tokens',
        'input',
        'reasoning',
        'tool_calling',
        'cost',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiCatalog $aiCatalog,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $userPath = $this->aiCatalog->userCatalogPath();

        try {
            $this->aiCatalog->ensureUserCatalog();

            $bundled = $this->aiCatalog->readBundledCatalog();
            if (null === $bundled) {
                $io->warning('Bundled AI catalog is missing or unreadable; nothing to update.');

                return Command::SUCCESS;
            }

            $user = $this->aiCatalog->readUserCatalog();
            $catalog = $this->rebase($bundled, $user);

            $response = $this->httpClient->request('GET', self::API_URL, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $io->warning(\sprintf('models.dev returned HTTP %d; left %s untouched.', $status, $userPath));

                return Command::SUCCESS;
            }

            try {
                $decoded = json_decode($response->getContent(false), true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $io->warning(\sprintf('models.dev returned invalid JSON (%s); left %s untouched.', $e->getMessage(), $userPath));

                return Command::SUCCESS;
            }

            if (!\is_array($decoded)) {
                $io->warning(\sprintf('models.dev payload was not an object; left %s untouched.', $userPath));

                return Command::SUCCESS;
            }

            $stats = $this->sync($catalog, $decoded);
            $this->aiCatalog->writeUserCatalog($catalog);

            $io->success(\sprintf(
                'Updated %s (version %d): +%d models, %d metadata refreshes.',
                $userPath,
                $catalog['version'],
                $stats['added'],
                $stats['updated'],
            ));
            if ([] !== $stats['added_ids']) {
                foreach ($stats['added_ids'] as $providerId => $ids) {
                    $io->writeln(\sprintf('  %s added: %s', $providerId, implode(', ', $ids)));
                }
            }
        } catch (\Throwable $e) {
            $io->warning(\sprintf('providers:update failed (%s); left %s untouched.', $e->getMessage(), $userPath));
        }

        return Command::SUCCESS;
    }

    /**
     * Start from bundled default; re-add user-only model ids the default lacks.
     *
     * @param array{version: int, providers: array<string, mixed>}      $bundled
     * @param array{version: int, providers: array<string, mixed>}|null $user
     *
     * @return array{version: int, providers: array<string, mixed>}
     */
    private function rebase(array $bundled, ?array $user): array
    {
        $out = [
            'version' => $bundled['version'],
            'providers' => $bundled['providers'],
        ];

        if (null === $user) {
            return $out;
        }

        foreach ($user['providers'] as $providerId => $userProvider) {
            if (!\is_string($providerId) || !\is_array($userProvider)) {
                continue;
            }

            $userModels = \is_array($userProvider['models'] ?? null) ? $userProvider['models'] : [];
            if (!isset($out['providers'][$providerId]) || !\is_array($out['providers'][$providerId])) {
                // Provider only in user copy: keep entire provider (user-added).
                $out['providers'][$providerId] = $userProvider;
                continue;
            }

            $defaultModels = \is_array($out['providers'][$providerId]['models'] ?? null)
                ? $out['providers'][$providerId]['models']
                : [];

            foreach ($userModels as $modelId => $modelData) {
                if (!\is_string($modelId) || !\is_array($modelData)) {
                    continue;
                }
                if (!\array_key_exists($modelId, $defaultModels)) {
                    $defaultModels[$modelId] = $modelData;
                }
            }

            $out['providers'][$providerId]['models'] = $defaultModels;
        }

        return $out;
    }

    /**
     * Apply models.dev metadata. Whitelist only — never connection fields.
     *
     * @param array{version: int, providers: array<string, mixed>} $catalog
     * @param array<string, mixed>                                 $upstream
     *
     * @return array{added: int, updated: int, added_ids: array<string, list<string>>}
     */
    private function sync(array &$catalog, array $upstream): array
    {
        $added = 0;
        $updated = 0;
        $addedIds = [];

        foreach ($catalog['providers'] as $hatfieldId => &$provider) {
            if (!\is_string($hatfieldId) || !\is_array($provider)) {
                continue;
            }

            $upstreamId = self::PROVIDER_ID_MAP[$hatfieldId] ?? null;
            if (null === $upstreamId) {
                continue;
            }
            $upstreamProvider = $upstream[$upstreamId] ?? null;
            if (!\is_array($upstreamProvider)) {
                continue;
            }
            $upstreamModels = \is_array($upstreamProvider['models'] ?? null) ? $upstreamProvider['models'] : [];
            if ([] === $upstreamModels) {
                continue;
            }

            $models = \is_array($provider['models'] ?? null) ? $provider['models'] : [];

            foreach ($upstreamModels as $modelId => $upstreamModel) {
                if (!\is_string($modelId) || '' === $modelId || !\is_array($upstreamModel)) {
                    continue;
                }

                $meta = $this->extractModelMetadata($upstreamModel);
                if ([] === $meta) {
                    continue;
                }

                if (\array_key_exists($modelId, $models) && \is_array($models[$modelId])) {
                    $changed = false;
                    foreach (self::METADATA_KEYS as $key) {
                        if (!\array_key_exists($key, $meta)) {
                            continue;
                        }
                        if (($models[$modelId][$key] ?? null) !== $meta[$key]) {
                            $models[$modelId][$key] = $meta[$key];
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        ++$updated;
                    }
                    continue;
                }

                $entry = $meta;
                $entry['name'] = \is_string($upstreamModel['name'] ?? null)
                    ? $upstreamModel['name']
                    : $modelId;
                $entry['thinking_level_map'] = $this->defaultThinkingLevelMap(
                    (bool) ($meta['reasoning'] ?? false),
                    $models,
                );
                $models[$modelId] = $entry;
                ++$added;
                $addedIds[$hatfieldId][] = $modelId;
            }

            $provider['models'] = $models;
        }
        unset($provider);

        return ['added' => $added, 'updated' => $updated, 'added_ids' => $addedIds];
    }

    /**
     * SECURITY: never copies api/base_url/paths/auth.
     *
     * @param array<string, mixed> $upstreamModel
     *
     * @return array<string, mixed>
     */
    private function extractModelMetadata(array $upstreamModel): array
    {
        $out = [];

        $limit = $upstreamModel['limit'] ?? null;
        if (\is_array($limit)) {
            if (isset($limit['context']) && is_numeric($limit['context'])) {
                $out['context_window'] = (int) $limit['context'];
            }
            if (isset($limit['output']) && is_numeric($limit['output'])) {
                $out['max_tokens'] = (int) $limit['output'];
            }
        }

        $modalities = $upstreamModel['modalities'] ?? null;
        if (\is_array($modalities) && isset($modalities['input']) && \is_array($modalities['input'])) {
            $input = [];
            foreach ($modalities['input'] as $modality) {
                if (\is_string($modality) && ('text' === $modality || 'image' === $modality)) {
                    $input[] = $modality;
                }
            }
            if ([] !== $input) {
                $out['input'] = array_values(array_unique($input));
            }
        }

        if (\array_key_exists('reasoning', $upstreamModel)) {
            $out['reasoning'] = (bool) $upstreamModel['reasoning'];
        }
        if (\array_key_exists('tool_call', $upstreamModel)) {
            $out['tool_calling'] = (bool) $upstreamModel['tool_call'];
        }

        $cost = $upstreamModel['cost'] ?? null;
        if (\is_array($cost)) {
            $mapped = [];
            foreach (['input', 'output', 'cache_read', 'cache_write'] as $field) {
                if (isset($cost[$field]) && is_numeric($cost[$field])) {
                    $mapped[$field] = (float) $cost[$field];
                }
            }
            if ([] !== $mapped) {
                $out['cost'] = $mapped;
            }
        }

        return $out;
    }

    /**
     * Copy thinking_level_map convention from an existing same-provider model
     * with matching reasoning flag; fall back to grok-composer-style nulls.
     *
     * @param array<string, mixed> $existingModels
     *
     * @return array<string, mixed>
     */
    private function defaultThinkingLevelMap(bool $reasoning, array $existingModels): array
    {
        foreach ($existingModels as $model) {
            if (!\is_array($model)) {
                continue;
            }
            if ((bool) ($model['reasoning'] ?? false) !== $reasoning) {
                continue;
            }
            $map = $model['thinking_level_map'] ?? null;
            if (\is_array($map) && [] !== $map) {
                return $map;
            }
        }

        return [
            'off' => 'none',
            'minimal' => null,
            'low' => null,
            'medium' => null,
            'high' => null,
            'xhigh' => null,
        ];
    }
}
