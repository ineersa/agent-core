<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

interface RegisteredToolInvokerInterface
{
    public function invoke(ToolInvocationRequestDTO $request): ToolInvocationResultDTO;
}
