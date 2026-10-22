<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory;

final readonly class RunPresentationProjectorFactory
{
    public function __construct(private ReplayAssistantMessageFactory $assistants, private ToolExecutionEndPayloadCodec $tools)
    {
    }

    public function create(): RunPresentationProjector
    {
        return new RunPresentationProjector($this->assistants, $this->tools, 0, 800);
    }
}
