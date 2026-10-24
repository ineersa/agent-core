<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion;

enum BackgroundCompletionOriginEnum: string
{
    case CodeMode = 'code_mode';
    case DeferredChild = 'deferred_child';
    case BackgroundProcess = 'background_process';
}
