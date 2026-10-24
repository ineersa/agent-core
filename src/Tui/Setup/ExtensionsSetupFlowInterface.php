<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

/**
 * Headless extensions:setup mutations + queries for {@see ExtensionsSetupScreen}.
 *
 * Lives in the Tui layer so the screen never imports CodingAgent. The AppCli
 * implementation wires catalog / installer / settings loader / writer.
 */
interface ExtensionsSetupFlowInterface
{
    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     builtin: bool,
     *     status: string,
     *     package: ?string,
     *     version: ?string,
     *     extensionClass: string,
     *     settingsKey: string,
     *     parentEnabled: bool,
     *     installAvailable: bool,
     *     needsInstall: bool
     * }>
     */
    public function extensionRows(): array;

    public function scopeLabel(): string;

    public function isProjectScope(): bool;

    /**
     * Switch the active settings layer. Project is the wizard default; user
     * writes ~/.hatfield/settings.yaml. Prefill excludes project-only values
     * when the user layer is selected.
     */
    public function setProjectScope(bool $project): void;

    public function wroteSomething(): bool;

    /**
     * @return list<string>
     */
    public function summaryLines(): array;

    /**
     * Prefill guided settings + child membership for one curated extension.
     *
     * @return array{
     *     id: string,
     *     name: string,
     *     builtin: bool,
     *     extensionClass: string,
     *     settingsKey: string,
     *     package: ?string,
     *     version: ?string,
     *     needsInstall: bool,
     *     parentEnabled: bool,
     *     forksAlwaysOn: bool,
     *     forksEnabled: bool,
     *     agentsAlwaysOn: bool,
     *     settings: array<string, mixed>,
     *     guidedFields: list<array{
     *         id: string,
     *         path: string,
     *         type: string,
     *         label: string,
     *         description: string,
     *         default: mixed
     *     }>,
     *     catalogModelIds: list<string>
     * }
     */
    public function formStateFor(string $id): array;

    /**
     * Exact provider/model ids from the configured catalog for guided model pickers.
     *
     * @return list<string>
     */
    public function catalogModelIds(): array;

    /**
     * Explicit Composer confirmation is required when the selected scope cannot
     * already load the package (project may reuse a user install; user may not
     * reuse a project install).
     */
    public function needsInstallConfirmation(string $id): bool;

    /**
     * Install the curated package for the active scope.
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function install(string $id): void;

    /**
     * Validate + persist guided settings, parent enablement, and child membership.
     *
     * Disable (parent off, no child membership) does not force install. Valid
     * guided edits still persist while disabled; untouched invalid/missing
     * guided values do not block disable-only. Writes stay sparse: unchanged
     * membership lists and guided leaves are not pinned into the selected
     * layer. Enablement requires a successful install and valid guided
     * configuration for non-built-in packages.
     *
     * @param array<string, mixed> $formState
     *
     * @throws \InvalidArgumentException when validation fails (typed ExtensionsSetupValidationException from the AppCli flow)
     * @throws \RuntimeException         when persistence fails
     */
    public function save(array $formState): void;

    /**
     * Encode a guided list field for the settings text editor.
     *
     * @param list<mixed>|array<mixed> $values
     */
    public function formatListField(array $values): string;

    /**
     * Decode a guided list editor value. Unchanged display text preserves
     * {@see $original}; edited text must be a YAML sequence.
     *
     * @param list<mixed>|array<mixed> $original
     *
     * @return list<string>
     */
    public function parseListField(string $value, array $original): array;
}
