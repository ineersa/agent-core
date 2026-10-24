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
     *     parentEnabled: bool,
     *     installAvailable: bool,
     *     needsInstall: bool
     * }>
     */
    public function extensionRows(): array;

    public function scopeLabel(): string;

    public function settingsPath(): string;

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
     *     package: ?string,
     *     version: ?string,
     *     needsInstall: bool,
     *     parentEnabled: bool,
     *     forksAlwaysOn: bool,
     *     forksEnabled: bool,
     *     agentsAlwaysOn: bool,
     *     settings: array<string, mixed>
     * }
     */
    public function formStateFor(string $id): array;

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
     * @param array<string, mixed> $formState
     *
     * @throws \InvalidArgumentException when validation fails
     * @throws \RuntimeException         when persistence fails
     */
    public function save(array $formState): void;

    /**
     * Enable an already-installable/builtin extension without rewriting settings.
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function enable(string $id): void;

    /**
     * Disable parent enablement for a curated extension.
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function disable(string $id): void;
}
