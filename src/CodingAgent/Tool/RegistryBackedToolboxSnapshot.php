<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Mutable revision-scoped cache holder for {@see RegistryBackedToolbox}.
 *
 * RegistryBackedToolbox is a readonly class, so the cached native Toolbox
 * and provider Tool list live behind this object and are swapped in place
 * when the registry revision changes. Rebuilding the native Toolbox is
 * expensive (reflection + JSON Schema generation per tool), so it happens
 * once per registry mutation instead of once per LLM step.
 */
final class RegistryBackedToolboxSnapshot
{
    /** @var int Registry revision this snapshot was built for; -1 = not built */
    public int $revision = -1;

    /** @var list<Tool>|null Provider-visible tool metadata in registry order */
    public ?array $tools = null;

    /** @var Toolbox|null Native toolbox covering every active handler */
    public ?Toolbox $toolbox = null;
}
