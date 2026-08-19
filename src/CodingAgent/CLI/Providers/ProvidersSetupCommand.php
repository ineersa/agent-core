<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Providers;

use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Interactive on-ramp for enabling AI providers from the bundled catalog.
 *
 * Known/catalog providers get sparse settings writes only ({@see enabled} +
 * optional {@see api_key}). Never writes {@see models} for those — catalog
 * inheritance must stay intact. Custom providers write a full definition.
 */
#[AsCommand(name: 'providers:setup', description: 'Interactive setup for AI providers')]
final class ProvidersSetupCommand
{
    private const ENV_VAR_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    /** @var list<string> */
    private const THINKING_LEVEL_KEYS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    public function __construct(
        private readonly AiCatalog $aiCatalog,
        private readonly SettingsOverrideWriter $settingsWriter,
        private readonly AppConfig $appConfig,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Write to project .hatfield/settings.yaml instead of ~/.hatfield/settings.yaml')]
        bool $project = false,
    ): int {
        $layer = $project ? SettingsLayerEnum::Project : SettingsLayerEnum::User;
        $cwd = '' !== $this->appConfig->cwd ? $this->appConfig->cwd : ((string) getcwd());
        if ('' === $cwd) {
            $cwd = '.';
        }
        $settingsPath = SettingsLayerEnum::Project === $layer
            ? rtrim($cwd, '/').'/.hatfield/settings.yaml'
            : \dirname($this->aiCatalog->userCatalogPath()).'/settings.yaml';

        $catalog = $this->loadCatalogProviders();
        if ([] === $catalog) {
            $io->error('No AI providers found. Ensure Hatfield is installed correctly.');

            return Command::FAILURE;
        }

        $io->title('AI Provider Setup');
        $io->text('Hatfield needs at least one AI provider to run.');

        /** @var list<array{id: string, models: list<string>, authCommand: ?string}> $configured */
        $configured = [];
        /** @var array<string, bool> $enabledThisRun true=enabled this run, false=disabled this run */
        $enabledThisRun = [];
        /** @var list<string> $disabledThisRun */
        $disabledThisRun = [];
        $wroteSomething = false;

        while (true) {
            $choice = $this->askProviderChoice($io, $catalog, $enabledThisRun);
            if ('done' === $choice) {
                break;
            }

            if ('custom' === $choice) {
                $result = $this->configureCustom($io, $layer, $cwd, $catalog);
                $configured[] = $result;
                $enabledThisRun[$result['id']] = true;
                $wroteSomething = true;
                $io->success(\sprintf('%s added.', $result['id']));
            } else {
                $provider = $catalog[$choice] ?? null;
                if (!\is_array($provider)) {
                    $io->warning(\sprintf('Unknown provider "%s".', $choice));
                    continue;
                }

                $displayName = $this->displayName($choice, $provider);
                $currentlyEnabled = $enabledThisRun[$choice] ?? $this->isProviderEnabled($choice);
                if ($currentlyEnabled) {
                    $action = $io->choice(
                        \sprintf('%s is already enabled. What do you want to do?', $displayName),
                        [
                            'configure' => 'Reconfigure',
                            'disable' => 'Disable',
                            'cancel' => 'Cancel',
                        ],
                        'configure',
                    );
                    if ('cancel' === $action) {
                        $io->writeln('Cancelled.');
                        if (!$io->confirm('Add another?', false)) {
                            break;
                        }
                        continue;
                    }
                    if ('disable' === $action) {
                        if (!$io->confirm(
                            \sprintf('Disable %s? This clears its settings entry.', $displayName),
                            false,
                        )) {
                            $io->writeln('Cancelled.');
                            if (!$io->confirm('Add another?', false)) {
                                break;
                            }
                            continue;
                        }
                        $this->disableProvider($io, $choice, $displayName, $layer, $cwd);
                        $enabledThisRun[$choice] = false;
                        $disabledThisRun[] = $choice;
                        $wroteSomething = true;
                        // Drop any earlier enable from this same run so default-model / auth hints skip it.
                        $configured = array_values(array_filter(
                            $configured,
                            static fn (array $row): bool => $row['id'] !== $choice,
                        ));
                        $this->warnIfDefaultModelPointsAtProvider($io, $choice);
                        if (!$io->confirm('Add another?', false)) {
                            break;
                        }
                        continue;
                    }
                    // 'configure' falls through to re-run enable flow
                }

                $result = $this->configureCatalogProvider($io, $choice, $provider, $displayName, $layer, $cwd);
                if (null !== $result) {
                    $configured[] = $result;
                    $enabledThisRun[$choice] = true;
                    $wroteSomething = true;
                    $kind = \is_string($provider['kind'] ?? null) ? $provider['kind'] : 'apikey';
                    if ('oauth' === $kind) {
                        $io->success(\sprintf('%s enabled.', $displayName));
                    } else {
                        $io->success(\sprintf('%s enabled.', $displayName));
                        $io->writeln('(Everything else is preconfigured.)');
                    }
                }
            }

            if (!$io->confirm('Add another?', false)) {
                break;
            }
        }

        if ([] === $configured && [] === $disabledThisRun) {
            $io->writeln('Nothing changed.');

            return Command::SUCCESS;
        }

        if ([] !== $configured) {
            $this->maybeSetDefaultModel($io, $configured, $layer, $cwd);
        }

        if ($wroteSomething) {
            $io->writeln(\sprintf('Saved to <comment>%s</comment>', $settingsPath));
        }

        $pendingAuth = [];
        foreach ($configured as $row) {
            if (null !== $row['authCommand'] && '' !== $row['authCommand']) {
                $pendingAuth[] = $row['authCommand'];
            }
        }
        if ([] !== $pendingAuth) {
            foreach (array_unique($pendingAuth) as $authCommand) {
                $io->writeln(\sprintf('To finish: run <info>`hatfield %s`</info> and log in.', $authCommand));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadCatalogProviders(): array
    {
        $this->aiCatalog->ensureUserCatalog();
        $raw = $this->aiCatalog->readUserCatalog() ?? $this->aiCatalog->readBundledCatalog();
        if (null === $raw) {
            return [];
        }

        $providers = $raw['providers'] ?? null;
        if (!\is_array($providers)) {
            return [];
        }

        $out = [];
        foreach ($providers as $id => $provider) {
            if (\is_string($id) && '' !== $id && \is_array($provider)) {
                $out[$id] = $provider;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $catalog
     * @param array<string, bool>                 $enabledThisRun
     */
    private function askProviderChoice(SymfonyStyle $io, array $catalog, array $enabledThisRun): string
    {
        $this->renderStatusTable($io, $catalog, $enabledThisRun);

        $choices = [];
        foreach ($catalog as $id => $provider) {
            $choices[$id] = $this->displayName($id, $provider).$this->providerBadge($id, $enabledThisRun);
        }
        $choices['custom'] = 'Other server';
        $choices['done'] = 'Done';

        $picked = $io->choice('Which provider?', $choices, 'done');

        return \is_string($picked) ? $picked : 'done';
    }

    /**
     * Live dashboard of catalog providers (+ any custom ones already configured).
     * Rendered every picker loop so enable/disable updates are visible immediately.
     *
     * @param array<string, array<string, mixed>> $catalog
     * @param array<string, bool>                 $enabledThisRun
     */
    private function renderStatusTable(SymfonyStyle $io, array $catalog, array $enabledThisRun): void
    {
        $io->writeln('');
        $rows = [];
        foreach ($catalog as $id => $provider) {
            $rows[] = [
                $this->displayName($id, $provider),
                $this->needHint($provider),
                $this->providerStatusLabel($id, $enabledThisRun),
            ];
        }

        foreach ($this->customProviderIds($catalog, $enabledThisRun) as $id) {
            $rows[] = [
                $id,
                'any OpenAI-compatible endpoint (llama.cpp, LM Studio, …)',
                '✓ enabled',
            ];
        }

        $io->table(['Provider', 'What it needs', 'Status'], $rows);
    }

    /**
     * @param array<string, array<string, mixed>> $catalog
     * @param array<string, bool>                 $enabledThisRun
     *
     * @return list<string>
     */
    private function customProviderIds(array $catalog, array $enabledThisRun): array
    {
        $ids = [];
        foreach ($enabledThisRun as $id => $enabled) {
            if ($enabled && !isset($catalog[$id])) {
                $ids[$id] = true;
            }
        }

        $configuredProviders = $this->appConfig->ai?->providers;
        if (\is_array($configuredProviders)) {
            foreach ($configuredProviders as $id => $provider) {
                if (!isset($catalog[$id]) && $provider->enabled && !isset($enabledThisRun[$id])) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<string, bool> $enabledThisRun
     */
    private function providerStatusLabel(string $id, array $enabledThisRun): string
    {
        $enabled = \array_key_exists($id, $enabledThisRun)
            ? $enabledThisRun[$id]
            : $this->isProviderEnabled($id);

        return $enabled ? '✓ enabled' : 'not set up';
    }

    /**
     * @param array<string, mixed> $provider
     */
    private function displayName(string $id, array $provider): string
    {
        $label = $provider['label'] ?? null;

        return \is_string($label) && '' !== $label ? $label : $id;
    }

    /**
     * @param array<string, mixed> $provider
     */
    private function needHint(array $provider): string
    {
        $kind = \is_string($provider['kind'] ?? null) ? $provider['kind'] : '';

        return match ($kind) {
            'oauth' => match ($provider['auth_command'] ?? null) {
                'auth:codex' => 'log in with your ChatGPT account',
                'auth:grok' => 'log in with your xAI account',
                default => 'log in with your account',
            },
            'apikey' => 'needs an API key',
            default => 'needs setup',
        };
    }

    private function isProviderEnabled(string $id): bool
    {
        $provider = $this->appConfig->ai?->providers[$id] ?? null;

        return null !== $provider && $provider->enabled;
    }

    /**
     * @param array<string, bool> $enabledThisRun
     */
    private function providerBadge(string $id, array $enabledThisRun): string
    {
        if (\array_key_exists($id, $enabledThisRun)) {
            return $enabledThisRun[$id] ? ' (enabled)' : ' (disabled)';
        }

        return $this->isProviderEnabled($id) ? ' (enabled)' : '';
    }

    private function disableProvider(
        SymfonyStyle $io,
        string $id,
        string $displayName,
        SettingsLayerEnum $layer,
        string $cwd,
    ): void {
        $this->settingsWriter->set($layer, $cwd, 'ai.providers.'.$id, [
            'enabled' => false,
        ]);
        $io->success(\sprintf('%s disabled.', $displayName));
    }

    private function warnIfDefaultModelPointsAtProvider(SymfonyStyle $io, string $providerId): void
    {
        $defaultModel = $this->appConfig->ai?->defaultModel;
        if (null === $defaultModel || '' === $defaultModel) {
            return;
        }

        $prefix = $providerId.'/';
        if (!str_starts_with($defaultModel, $prefix)) {
            return;
        }

        $io->warning(\sprintf(
            'Your default model "%s" is now unavailable. Run setup again to pick another.',
            $defaultModel,
        ));
    }

    /**
     * @param array<string, mixed> $provider
     *
     * @return array{id: string, models: list<string>, authCommand: ?string}|null
     */
    private function configureCatalogProvider(
        SymfonyStyle $io,
        string $id,
        array $provider,
        string $displayName,
        SettingsLayerEnum $layer,
        string $cwd,
    ): ?array {
        $kind = \is_string($provider['kind'] ?? null) ? $provider['kind'] : 'apikey';
        $authCommand = \is_string($provider['auth_command'] ?? null) && '' !== $provider['auth_command']
            ? $provider['auth_command']
            : null;
        $models = $this->modelIdsFromProvider($provider);

        if ('oauth' === $kind) {
            $this->settingsWriter->set($layer, $cwd, 'ai.providers.'.$id, [
                'enabled' => true,
            ]);

            // Auth hint is emitted once at the end from $configured — skip if later disabled same run.

            return ['id' => $id, 'models' => $models, 'authCommand' => $authCommand];
        }

        $apiKey = $this->askApiKey($io, $id);
        if (null === $apiKey) {
            $io->warning('Skipped — no API key provided.');

            return null;
        }

        $this->settingsWriter->set($layer, $cwd, 'ai.providers.'.$id, [
            'enabled' => true,
            'api_key' => $apiKey,
        ]);

        return ['id' => $id, 'models' => $models, 'authCommand' => null];
    }

    private function askApiKey(SymfonyStyle $io, string $providerId): ?string
    {
        $suggestedEnv = strtoupper(str_replace('-', '_', $providerId)).'_API_KEY';
        $where = $io->choice(
            'API key: read from an environment variable, or paste it now?',
            [
                'env' => 'environment variable',
                'raw' => 'paste',
            ],
            'env',
        );

        if ('raw' === $where) {
            $raw = trim((string) $io->askHidden('Paste your API key', static function (?string $value): string {
                $value = trim((string) $value);
                if ('' === $value) {
                    throw new \InvalidArgumentException('API key cannot be empty.');
                }

                return $value;
            }));

            return '' === $raw ? null : $raw;
        }

        $envName = trim((string) $io->ask(
            'Variable name',
            $suggestedEnv,
            static function (mixed $value) use ($suggestedEnv): string {
                $value = trim(\is_string($value) ? $value : $suggestedEnv);
                if (1 !== preg_match(self::ENV_VAR_PATTERN, $value)) {
                    throw new \InvalidArgumentException('Env var must match ^[A-Z][A-Z0-9_]*$.');
                }

                return $value;
            },
        ));

        return 'env:'.$envName;
    }

    /**
     * @param array<string, array<string, mixed>> $catalog
     *
     * @return array{id: string, models: list<string>, authCommand: null}
     */
    private function configureCustom(SymfonyStyle $io, SettingsLayerEnum $layer, string $cwd, array $catalog): array
    {
        $id = trim((string) $io->ask('Provider id (slug)', 'local', static function (?string $value) use ($catalog): string {
            $value = strtolower(trim((string) $value));
            if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $value)) {
                throw new \InvalidArgumentException('Provider id must match ^[a-z][a-z0-9_-]*$.');
            }
            if (isset($catalog[$value])) {
                throw new \InvalidArgumentException(\sprintf('"%s" is built into Hatfield — choose it from the list above instead.', $value));
            }

            return $value;
        }));

        $baseUrl = trim((string) $io->ask('Server URL (e.g. http://localhost:8080)', 'http://127.0.0.1:8080', static function (?string $value): string {
            $value = trim((string) $value);
            if ('' === $value) {
                throw new \InvalidArgumentException('Server URL is required.');
            }

            return rtrim($value, '/');
        }));

        $completionsPath = trim((string) $io->ask('Completions path', '/v1/chat/completions'));
        if ('' === $completionsPath) {
            $completionsPath = '/v1/chat/completions';
        }
        if (!str_starts_with($completionsPath, '/')) {
            $completionsPath = '/'.$completionsPath;
        }

        $includeKey = $io->confirm('Set an API key?', false);
        $apiKey = $includeKey ? $this->askApiKey($io, $id) : null;

        $models = [];
        $io->writeln('Add at least one model (Enter keeps the default).');
        do {
            $modelId = trim((string) $io->ask('Model id (e.g. llama-3.3-70b)', 'default', static function (?string $value): string {
                $value = trim((string) $value);
                if ('' === $value) {
                    throw new \InvalidArgumentException('Model id is required.');
                }

                return $value;
            }));
            $name = trim((string) $io->ask('Display name', $modelId));
            $contextWindow = (int) $io->ask('Context window', '128000', static function (?string $value): int {
                $n = (int) trim((string) $value);
                if ($n < 1) {
                    throw new \InvalidArgumentException('Context window must be >= 1.');
                }

                return $n;
            });
            $maxTokens = (int) $io->ask('Max output tokens', '8192', static function (?string $value): int {
                $n = (int) trim((string) $value);
                if ($n < 1) {
                    throw new \InvalidArgumentException('Max tokens must be >= 1.');
                }

                return $n;
            });
            $inputChoice = $io->choice('Input modalities', ['text', 'text+image'], 'text');
            $input = 'text+image' === $inputChoice ? ['text', 'image'] : ['text'];
            $reasoning = $io->confirm('Supports reasoning/thinking?', false);
            $thinkingLevelMap = [];
            if ($reasoning) {
                // ponytail: identity map for custom providers; override in settings when the backend uses different effort labels
                foreach (self::THINKING_LEVEL_KEYS as $level) {
                    $thinkingLevelMap[$level] = 'off' === $level ? 'none' : $level;
                }
            }

            $models[$modelId] = [
                'name' => '' !== $name ? $name : $modelId,
                'context_window' => $contextWindow,
                'max_tokens' => $maxTokens,
                'input' => $input,
                'tool_calling' => true,
                'reasoning' => $reasoning,
                'thinking_level_map' => $thinkingLevelMap,
                'cost' => [
                    'input' => 0,
                    'output' => 0,
                    'cache_read' => 0,
                    'cache_write' => 0,
                ],
            ];
        } while ($io->confirm('Add another model?', false));

        $supportsDeveloperRole = $io->confirm('Allow developer-role messages?', false);
        $thinkingFormat = trim((string) $io->ask('Reasoning format label (blank = none)', ''));

        $definition = [
            'type' => 'generic',
            'enabled' => true,
            'base_url' => $baseUrl,
            'api' => 'openai-completions',
            'completions_path' => $completionsPath,
            'supports_completions' => true,
            'supports_embeddings' => false,
            'models' => $models,
        ];
        if (null !== $apiKey && '' !== $apiKey) {
            $definition['api_key'] = $apiKey;
        }

        $compatibility = ['supports_developer_role' => $supportsDeveloperRole];
        if ('' !== $thinkingFormat) {
            $compatibility['thinking_format'] = $thinkingFormat;
        }
        $definition['compatibility'] = $compatibility;

        $this->settingsWriter->set($layer, $cwd, 'ai.providers.'.$id, $definition);

        return [
            'id' => $id,
            'models' => array_keys($models),
            'authCommand' => null,
        ];
    }

    /**
     * @param list<array{id: string, models: list<string>, authCommand: ?string}> $configured
     */
    private function maybeSetDefaultModel(
        SymfonyStyle $io,
        array $configured,
        SettingsLayerEnum $layer,
        string $cwd,
    ): void {
        if (!$io->confirm('Set as your default model?', true)) {
            return;
        }

        $choices = [];
        foreach ($configured as $row) {
            foreach ($row['models'] as $modelId) {
                $ref = $row['id'].'/'.$modelId;
                $choices[$ref] = $ref;
            }
        }
        if ([] === $choices) {
            $io->warning('No models available to set as default.');

            return;
        }

        $picked = $io->choice('Default model', $choices, array_key_first($choices));
        if (!\is_string($picked) || '' === $picked) {
            return;
        }

        $this->settingsWriter->set($layer, $cwd, 'ai.default_model', $picked);
        $io->success(\sprintf('Default model set to %s.', $picked));
    }

    /**
     * @param array<string, mixed> $provider
     *
     * @return list<string>
     */
    private function modelIdsFromProvider(array $provider): array
    {
        $models = $provider['models'] ?? null;
        if (!\is_array($models)) {
            return [];
        }

        $ids = [];
        foreach ($models as $modelId => $_) {
            if (\is_string($modelId) && '' !== $modelId) {
                $ids[] = $modelId;
            }
        }

        return $ids;
    }
}
