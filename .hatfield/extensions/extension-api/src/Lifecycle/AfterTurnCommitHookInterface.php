<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

interface AfterTurnCommitHookInterface
{
    public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void;
}
