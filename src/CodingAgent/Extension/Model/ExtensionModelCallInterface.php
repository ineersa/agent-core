<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Model;

use Ineersa\Hatfield\ExtensionApi\Model\ModelCallResultDTO;

/**
 * Internal adapter contract for ExtensionApiInterface::callModel().
 *
 * @internal
 */
interface ExtensionModelCallInterface
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null  $structuredContent
     */
    public function call(
        string $model,
        array $messages,
        array $tools = [],
        ?array $structuredContent = null,
    ): ModelCallResultDTO;
}
