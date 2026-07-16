<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;

final class ForkToolDefinitionProvider implements HatfieldToolProviderInterface
{
    public function __construct(
        private readonly ForkToolHandler $handler,
    ) {
    }

    public function definition(): ToolDefinitionDTO
    {
        return ForkToolDefinitionBuilder::build($this->handler);
    }
}
