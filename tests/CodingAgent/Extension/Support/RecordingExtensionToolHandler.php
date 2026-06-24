<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Support;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;

/**
 * Records invocations for adapter passthrough assertions.
 */
final class RecordingExtensionToolHandler implements ExtensionToolHandlerInterface
{
    /** @var list<array<string, mixed>> */
    public array $invocations = [];

    public function __invoke(array $arguments): mixed
    {
        $this->invocations[] = $arguments;

        return 'extension handler result';
    }
}
