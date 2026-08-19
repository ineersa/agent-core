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
#[AsCommand(name: 'providers:setup', description: 'Interactive setup for AI providers (catalog presets + custom OpenAI-compatible)')]
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
            $io->error('No AI catalog providers found. Ensure config/ai-catalog.yaml is present.');

            return Command::FAILURE;
        }

        $io->title('AI Provider Setup');
        $io->text([
            'Hatfield ships with no providers enabled.',
            'Pick a provider to configure. Catalog connection settings and models stay inherited — only enable/key overrides are written for known providers.',
            \sprintf('Writing to <info>%s</info> layer: <comment>%s</comment>', $layer->value, $settingsPath),
        ]);

        /** @var list<array{id: string, models: list<string>, authCommand: ?string}> $configured */
        $configured = [];
        /** @var array<string, bool> $enabledThisRun true=enabled this run, false=disabled this run */
        $enabledThisRun = [];
        /** @var list<string> $disabledThisRun */
        $disabledThisRun = [];

        while (true) {
            $choice = $this->askProviderChoice($io, $catalog, $enabledThisRun);
            if ('done' === $choice) {
                break;
            }

            if ('custom' === $choice) {
                $result = $this->configureCustom($io, $layer, $cwd);
                $configured[] = $result;
                $enabledThisRun[$result['id']] = true;
                $io->success(\sprintf('Custom provider "%s" written.', $result['id']));
            } else {
                $provider = $catalog[$choice] ?? null;
                if (!\is_array($provider)) {
                    $io->warning(\sprintf('Unknown catalog provider "%s".', $choice));
                    continue;
                }

                $currentlyEnabled = $enabledThisRun[$choice] ?? $this->isProviderEnabled($choice);
                if ($currentlyEnabled) {
                    $action = $io->choice(
                        \sprintf('Provider "%s" is enabled. What do you want to do?', $choice),
                        [
                            'configure' => 'Reconfigure (keep enabled)',
                            'disable' => 'Disable',
                            'cancel' => 'Cancel',
                        ],
                        'configure',
                    );
                    if ('cancel' === $action) {
                        $io->writeln('Cancelled.');
                        if (!$io->confirm('Add another provider?', true)) {
                            break;
                        }
                        continue;
                    }
                    if ('disable' === $action) {
                        $this->disableProvider($io, $choice, $layer, $cwd);
                        $enabledThisRun[$choice] = false;
                        $disabledThisRun[] = $choice;
                        // Drop any earlier enable from this same run so default-model / auth hints skip it.
                        $configured = array_values(array_filter(
                            $configured,
                            static fn (array $row): bool => $row['id'] !== $choice,
                        ));
                        $this->warnIfDefaultModelPointsAtProvider($io, $choice);
                        if (!$io->confirm('Add another provider?', true)) {
                            break;
                        }
                        continue;
                    }
                    // 'configure' falls through to re-run enable flow
                }

                $result = $this->configureCatalogProvider($io, $choice, $provider, $layer, $cwd);
                if (null !== $result) {
                    $configured[] = $result;
                    $enabledThisRun[$choice] = true;
                    $io->success(\sprintf('Provider "%s" enabled.', $choice));
                }
            }

            if (!$io->confirm('Add another provider?', true)) {
                break;
            }
        }

        if ([] === $configured && [] === $disabledThisRun) {
            $io->writeln('No providers configured.');

            return Command::SUCCESS;
        }

        if ([] !== $configured) {
            $this->maybeSetDefaultModel($io, $configured, $layer, $cwd);
        }

        $io->section('Done');
        if ([] !== $configured) {
            $io->listing(array_map(
                static fn (array $row): string => \sprintf('%s (%d model(s))', $row['id'], \count($row['models'])),
                $configured,
            ));
        }
        if ([] !== $disabledThisRun) {
            $io->writeln('Disabled: <comment>'.implode(', ', array_unique($disabledThisRun)).'</comment>');
        }
        $io->writeln(\sprintf('Settings written: <comment>%s</comment>', $settingsPath));

        $pendingAuth = [];
        foreach ($configured as $row) {
            if (null !== $row['authCommand'] && '' !== $row['authCommand']) {
                $pendingAuth[] = $row['authCommand'];
            }
        }
        if ([] !== $pendingAuth) {
            $io->warning('OAuth providers need a login before first use:');
            foreach (array_unique($pendingAuth) as $authCommand) {
                $io->writeln(\sprintf('  Next step: run <info>`hatfield %s`</info>', $authCommand));
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
        $choices = [];
        foreach ($catalog as $id => $provider) {
            $label = \is_string($provider['label'] ?? null) && '' !== $provider['label']
                ? $provider['label']
                : $id;
            $kind = \is_string($provider['kind'] ?? null) ? $provider['kind'] : '';
            $suffix = match ($kind) {
                'oauth' => ' (OAuth)',
                'apikey' => '',
                default => '' !== $kind ? ' ('.$kind.')' : '',
            };
            $badge = $this->providerBadge($id, $enabledThisRun);
            $choices[$id] = $label.$suffix.$badge;
        }
        $choices['custom'] = 'Custom OpenAI-compatible (llama.cpp, RunPod, LM Studio, …)';
        $choices['done'] = 'Done';

        $picked = $io->choice('Provider to set up', $choices, 'done');

        return \is_string($picked) ? $picked : 'done';
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
            return $enabledThisRun[$id] ? ' [enabled]' : ' [disabled]';
        }

        return $this->isProviderEnabled($id) ? ' [enabled]' : '';
    }

    private function disableProvider(
        SymfonyStyle $io,
        string $id,
        SettingsLayerEnum $layer,
        string $cwd,
    ): void {
        $this->settingsWriter->set($layer, $cwd, 'ai.providers.'.$id, [
            'enabled' => false,
        ]);
        $io->success(\sprintf('Provider "%s" disabled.', $id));
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
            'ai.default_model "%s" points at disabled provider "%s" and is now unavailable. Re-run setup to pick another default model.',
            $defaultModel,
            $providerId,
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
        SettingsLayerEnum $layer,
        string $cwd,
    ): ?array {
        $kind = \is_string($provider['kind'] ?? null) ? $provider['kind'] : 'apikey';
        $authCommand = \is_string($provider['auth_command'] ?? null) && '' !== $provider['auth_command']
            ? $provider['auth_command']
            : null;
        $models = $this->modelIdsFromProvider($provider);

        $io->section(\sprintf('Configure %s', $id));

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
            'Where is your API key?',
            [
                'env' => \sprintf('Environment variable (recommended; e.g. %s)', $suggestedEnv),
                'raw' => 'Paste raw key into settings',
            ],
            'env',
        );

        if ('raw' === $where) {
            $raw = trim((string) $io->ask('Paste API key', null, static function (?string $value): string {
                $value = trim((string) $value);
                if ('' === $value) {
                    throw new \InvalidArgumentException('API key cannot be empty.');
                }

                return $value;
            }));

            return '' === $raw ? null : $raw;
        }

        $envName = trim((string) $io->ask(
            'Environment variable name',
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
     * @return array{id: string, models: list<string>, authCommand: null}
     */
    private function configureCustom(SymfonyStyle $io, SettingsLayerEnum $layer, string $cwd): array
    {
        $io->section('Custom OpenAI-compatible provider');

        $id = trim((string) $io->ask('Provider id (slug)', 'local', static function (?string $value): string {
            $value = strtolower(trim((string) $value));
            if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $value)) {
                throw new \InvalidArgumentException('Provider id must match ^[a-z][a-z0-9_-]*$.');
            }

            return $value;
        }));

        $baseUrl = trim((string) $io->ask('Base URL', 'http://127.0.0.1:8080', static function (?string $value): string {
            $value = trim((string) $value);
            if ('' === $value) {
                throw new \InvalidArgumentException('Base URL is required.');
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
        $io->writeln('Add at least one model (Enter accepts defaults).');
        do {
            $modelId = trim((string) $io->ask('Model id', 'default', static function (?string $value): string {
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

        $supportsDeveloperRole = $io->confirm('supports_developer_role?', false);
        $thinkingFormat = trim((string) $io->ask('thinking_format (empty = omit)', ''));

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
        if (!$io->confirm('Set default model?', true)) {
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
