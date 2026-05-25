<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Interface that Hatfield extension classes must implement.
 *
 * Extensions registered in settings under `extensions.enabled` are instantiated
 * and receive an ExtensionApiInterface via register() at startup, before the
 * runtime loop begins.
 */
interface HatfieldExtensionInterface
{
    /**
     * Called at startup to give the extension access to the Hatfield extension API.
     *
     * Extensions should call $api->registerTool(...) for each tool they provide.
     */
    public function register(ExtensionApiInterface $api): void;
}
