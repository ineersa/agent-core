<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Providers;

use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\Tui\Setup\ProvidersSetupFlowInterface;

/**
 * Headless providers:setup mutations. Owns sparse writes + in-run state;
 * the TUI screen drives I/O against {@see ProvidersSetupFlowInterface}.
 */
final class ProvidersSetupFlow implements ProvidersSetupFlowInterface
{
    private const ENV_VAR_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    /** @var list<string> */
    private const THINKING_LEVEL_KEYS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    /** @var array<string, array<string, mixed>> */
    private array $catalog;

    /** @var array<string, bool> true=enabled this run, false=disabled this run */
    private array $enabledThisRun = [];

    /** @var list<array{id: string, models: list<string>, authCommand: ?string}> */
    private array $configured = [];

    private bool $wroteSomething = false;

    public function __construct(
        private readonly AiCatalog $aiCatalog,
        private readonly SettingsOverrideWriter $settingsWriter,
        private readonly AppConfig $appConfig,
        private readonly SettingsLayerEnum $layer,
        private readonly string $cwd,
    ) {
        $this->catalog = $this->loadCatalogProviders();
    }

    public static function for(
        AiCatalog $aiCatalog,
        SettingsOverrideWriter $settingsWriter,
        AppConfig $appConfig,
        bool $project = false,
    ): self {
        $layer = $project ? SettingsLayerEnum::Project : SettingsLayerEnum::User;
        $cwd = '' !== $appConfig->cwd ? $appConfig->cwd : ((string) getcwd());
        if ('' === $cwd) {
            $cwd = '.';
        }

        return new self($aiCatalog, $settingsWriter, $appConfig, $layer, $cwd);
    }

    public function catalogEmpty(): bool
    {
        return [] === $this->catalog;
    }

    public function providerRows(): array
    {
        $rows = [];
        foreach ($this->catalog as $id => $provider) {
            $rows[] = [
                'id' => $id,
                'label' => $this->displayName($id, $provider),
                'need' => $this->needHint($provider),
                'status' => $this->providerStatusLabel($id),
                'kind' => \is_string($provider['kind'] ?? null) ? $provider['kind'] : 'apikey',
                'authCommand' => \is_string($provider['auth_command'] ?? null) && '' !== $provider['auth_command']
                    ? $provider['auth_command']
                    : null,
                'models' => $this->modelIdsFromProvider($provider),
            ];
        }

        foreach ($this->customProviderIds() as $id) {
            $rows[] = [
                'id' => $id,
                'label' => $id,
                'need' => 'any OpenAI-compatible endpoint (llama.cpp, LM Studio, …)',
                'status' => '✓ enabled',
                'kind' => 'custom',
                'authCommand' => null,
                'models' => [],
            ];
        }

        return $rows;
    }

    public function isEnabled(string $id): bool
    {
        if (\array_key_exists($id, $this->enabledThisRun)) {
            return $this->enabledThisRun[$id];
        }

        $provider = $this->appConfig->ai?->providers[$id] ?? null;

        return null !== $provider && $provider->enabled;
    }

    public function enableOauth(string $id): void
    {
        $provider = $this->requireCatalogProvider($id);
        $authCommand = \is_string($provider['auth_command'] ?? null) && '' !== $provider['auth_command']
            ? $provider['auth_command']
            : null;

        $this->settingsWriter->set($this->layer, $this->cwd, 'ai.providers.'.$id, [
            'enabled' => true,
        ]);
        $this->markEnabled($id, $this->modelIdsFromProvider($provider), $authCommand);
    }

    public function enableApiKey(string $id, string $apiKey): void
    {
        $provider = $this->requireCatalogProvider($id);
        $this->settingsWriter->set($this->layer, $this->cwd, 'ai.providers.'.$id, [
            'enabled' => true,
            'api_key' => $apiKey,
        ]);
        $this->markEnabled($id, $this->modelIdsFromProvider($provider), null);
    }

    public function disable(string $id): void
    {
        $this->settingsWriter->set($this->layer, $this->cwd, 'ai.providers.'.$id, [
            'enabled' => false,
        ]);
        $this->enabledThisRun[$id] = false;
        $this->wroteSomething = true;
        $this->configured = array_values(array_filter(
            $this->configured,
            static fn (array $row): bool => $row['id'] !== $id,
        ));
    }

    public function validateCustomId(string $id): void
    {
        $id = strtolower(trim($id));
        if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $id)) {
            throw new \InvalidArgumentException('Provider id must match ^[a-z][a-z0-9_-]*$.');
        }
        if (isset($this->catalog[$id])) {
            throw new \InvalidArgumentException(\sprintf('"%s" is built into Hatfield — choose it from the list above instead.', $id));
        }
    }

    public function saveCustom(
        string $id,
        string $baseUrl,
        string $completionsPath,
        ?string $apiKey,
        array $models,
        bool $supportsDeveloperRole,
        string $thinkingFormat,
    ): void {
        $this->validateCustomId($id);
        $id = strtolower(trim($id));
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ('' === $baseUrl) {
            throw new \InvalidArgumentException('Server URL is required.');
        }
        $completionsPath = trim($completionsPath);
        if ('' === $completionsPath) {
            $completionsPath = '/v1/chat/completions';
        }
        if (!str_starts_with($completionsPath, '/')) {
            $completionsPath = '/'.$completionsPath;
        }
        if ([] === $models) {
            throw new \InvalidArgumentException('Add at least one model.');
        }

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
        if ('' !== trim($thinkingFormat)) {
            $compatibility['thinking_format'] = trim($thinkingFormat);
        }
        $definition['compatibility'] = $compatibility;

        $this->settingsWriter->set($this->layer, $this->cwd, 'ai.providers.'.$id, $definition);
        $this->markEnabled($id, array_keys($models), null);
    }

    public function setDefaultModel(string $ref): void
    {
        $this->settingsWriter->set($this->layer, $this->cwd, 'ai.default_model', $ref);
        $this->wroteSomething = true;
    }

    public function pendingAuthCommands(): array
    {
        $pending = [];
        foreach ($this->configured as $row) {
            if (null !== $row['authCommand'] && '' !== $row['authCommand']) {
                $pending[] = $row['authCommand'];
            }
        }

        return array_values(array_unique($pending));
    }

    public function configuredModelRefs(): array
    {
        $refs = [];
        foreach ($this->configured as $row) {
            foreach ($row['models'] as $modelId) {
                $refs[] = $row['id'].'/'.$modelId;
            }
        }

        return $refs;
    }

    public function settingsPath(): string
    {
        return SettingsLayerEnum::Project === $this->layer
            ? rtrim($this->cwd, '/').'/.hatfield/settings.yaml'
            : \dirname($this->aiCatalog->userCatalogPath()).'/settings.yaml';
    }

    public function wroteSomething(): bool
    {
        return $this->wroteSomething;
    }

    public function defaultModelWarningFor(string $providerId): ?string
    {
        $defaultModel = $this->appConfig->ai?->defaultModel;
        if (null === $defaultModel || '' === $defaultModel) {
            return null;
        }

        if (!str_starts_with($defaultModel, $providerId.'/')) {
            return null;
        }

        return \sprintf(
            'Your default model "%s" is now unavailable. Run setup again to pick another.',
            $defaultModel,
        );
    }

    public function formatEnvApiKey(string $envName): string
    {
        $envName = trim($envName);
        if (1 !== preg_match(self::ENV_VAR_PATTERN, $envName)) {
            throw new \InvalidArgumentException('Env var must match ^[A-Z][A-Z0-9_]*$.');
        }

        return 'env:'.$envName;
    }

    public function defaultThinkingLevelMap(): array
    {
        $map = [];
        // ponytail: identity map for custom providers; override in settings when the backend uses different effort labels
        foreach (self::THINKING_LEVEL_KEYS as $level) {
            $map[$level] = 'off' === $level ? 'none' : $level;
        }

        return $map;
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
     * @return list<string>
     */
    private function customProviderIds(): array
    {
        $ids = [];
        foreach ($this->enabledThisRun as $id => $enabled) {
            if ($enabled && !isset($this->catalog[$id])) {
                $ids[$id] = true;
            }
        }

        $configuredProviders = $this->appConfig->ai?->providers;
        if (\is_array($configuredProviders)) {
            foreach ($configuredProviders as $id => $provider) {
                if (!isset($this->catalog[$id]) && $provider->enabled && !isset($this->enabledThisRun[$id])) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function providerStatusLabel(string $id): string
    {
        return $this->isEnabled($id) ? '✓ enabled' : 'not set up';
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

    /**
     * @return array<string, mixed>
     */
    private function requireCatalogProvider(string $id): array
    {
        $provider = $this->catalog[$id] ?? null;
        if (!\is_array($provider)) {
            throw new \InvalidArgumentException(\sprintf('Unknown provider "%s".', $id));
        }

        return $provider;
    }

    /**
     * @param list<string> $models
     */
    private function markEnabled(string $id, array $models, ?string $authCommand): void
    {
        $this->enabledThisRun[$id] = true;
        $this->wroteSomething = true;
        $this->configured = array_values(array_filter(
            $this->configured,
            static fn (array $row): bool => $row['id'] !== $id,
        ));
        $this->configured[] = [
            'id' => $id,
            'models' => $models,
            'authCommand' => $authCommand,
        ];
    }
}
