<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\Tui\Runtime\BridgeTuiExtensionContext;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/** Gives extensions their TUI context. Session events come from CodingAgent, not the TUI. */
final class TuiProjectExtensionRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly ExtensionManager $extensionManager,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $bridge = new BridgeTuiExtensionContext($context);
        foreach ($this->extensionManager->getTuiExtensions() as $extension) {
            $extension->registerTui($bridge);
        }
    }
}
