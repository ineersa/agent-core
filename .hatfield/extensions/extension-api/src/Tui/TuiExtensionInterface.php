<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

/**
 * Optional interactive TUI wiring for Hatfield extensions.
 *
 * The host passes a typed {@see TuiExtensionContextInterface} backed by Symfony TUI
 * widgets and Hatfield overlay slots (Option B: Symfony TUI types on the public API).
 */
interface TuiExtensionInterface
{
    public function registerTui(TuiExtensionContextInterface $context): void;
}
