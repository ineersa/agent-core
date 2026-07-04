<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\TuiExtensionRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Ineersa\Tui\Runtime\BridgeTuiExtensionContext;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

final class TuiProjectExtensionRegistrar implements TuiListenerRegistrar
{
    public function __construct(private readonly TuiExtensionRegistryInterface $tuiExtensions)
    {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $bridge = new BridgeTuiExtensionContext($context);
        foreach ($this->tuiExtensions->getTuiExtensions() as $extension) {
            if ($extension instanceof TuiExtensionInterface) {
                $extension->registerTui($bridge);
            }
        }
    }
}
