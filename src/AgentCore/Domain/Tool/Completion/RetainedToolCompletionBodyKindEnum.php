<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

enum RetainedToolCompletionBodyKindEnum: string
{
    case Inline = 'inline';
    case OwnedSealed = 'owned_sealed';
    case NativeArtifactHandoff = 'native_artifact_handoff';
}
