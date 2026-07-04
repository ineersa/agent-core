<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\TuiProjectExtensionRegistryInterface;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

final class TuiProjectExtensionRegistrar implements TuiListenerRegistrar
{
    public function __construct(private readonly TuiProjectExtensionRegistryInterface $tuiExtensions)
    {
    }

    public function register(TuiRuntimeContext $context): void
    {
        foreach ($this->tuiExtensions->getTuiProjectExtensions() as $extension) {
            if (!\is_object($extension) || !method_exists($extension, 'registerTui')) {
                continue;
            }
            $extension->registerTui($context);
        }
    }
}
