<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Support;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;

/**
 * Minimal extension tool handler for tests that only need a valid registration.
 */
final readonly class NoOpExtensionToolHandler implements ExtensionToolHandlerInterface
{
    public function __invoke(array $arguments): mixed
    {
        return null;
    }
}
