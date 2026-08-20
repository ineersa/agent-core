<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

/**
 * Headless provider-setup mutations + queries for {@see SetupScreen}.
 *
 * Lives in the Tui layer so SetupScreen never imports CodingAgent. The
 * AppCli implementation wires AiCatalog / SettingsOverrideWriter / AppConfig.
 */
interface ProvidersSetupFlowInterface
{
    /**
     * Catalog rows for the main picker (customs live under the Other-server submenu).
     *
     * @return list<array{
     *     id: string,
     *     label: string,
     *     need: string,
     *     status: string,
     *     kind: string,
     *     authCommand: ?string,
     *     models: list<string>
     * }>
     */
    public function providerRows(): array;

    /**
     * Saved custom (non-catalog) providers for the Other-server submenu.
     *
     * @return list<array{id: string, url: string, enabled: bool}>
     */
    public function customProviderRows(): array;

    /**
     * Prefill payload for editing a custom provider, or null if unknown.
     *
     * @return array{
     *     id: string,
     *     baseUrl: string,
     *     completionsPath: string,
     *     apiKey: ?string,
     *     models: array<string, array<string, mixed>>,
     *     supportsDeveloperRole: bool,
     *     thinkingFormat: string
     * }|null
     */
    public function customDefinition(string $id): ?array;

    public function isEnabled(string $id): bool;

    public function enableOauth(string $id): void;

    public function enableApiKey(string $id, string $apiKey): void;

    /** Re-enable a saved custom provider via full-definition rewrite (sparse {enabled:true} would wipe its config). */
    public function enableCustom(string $id): void;

    public function disable(string $id): void;

    /** Delete ai.providers.<id> from the active settings layer. */
    public function removeCustom(string $id): void;

    /**
     * @throws \InvalidArgumentException when id is invalid or collides with catalog
     */
    public function validateCustomId(string $id): void;

    /**
     * Persist a full custom provider definition (models included).
     *
     * @param array<string, array<string, mixed>> $models
     *
     * @throws \InvalidArgumentException when id is invalid or collides with catalog
     */
    public function saveCustom(
        string $id,
        string $baseUrl,
        string $completionsPath,
        ?string $apiKey,
        array $models,
        bool $supportsDeveloperRole,
        string $thinkingFormat,
    ): void;

    public function setDefaultModel(string $ref): void;

    /**
     * @return list<string> auth command names still pending (e.g. auth:grok)
     */
    public function pendingAuthCommands(): array;

    /**
     * @return list<string> provider/model refs configured this run
     */
    public function configuredModelRefs(): array;

    public function settingsPath(): string;

    public function wroteSomething(): bool;

    public function defaultModelWarningFor(string $providerId): ?string;

    /**
     * Format an env-var API key as env:NAME.
     *
     * @throws \InvalidArgumentException when name does not match ^[A-Z][A-Z0-9_]*$
     */
    public function formatEnvApiKey(string $envName): string;

    /**
     * Identity thinking_level_map for custom providers that support reasoning.
     *
     * @return array<string, string>
     */
    public function defaultThinkingLevelMap(): array;
}
