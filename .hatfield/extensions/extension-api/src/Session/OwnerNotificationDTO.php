<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session;

final readonly class OwnerNotificationDTO
{
    public function __construct(public string $runId, public string $identity, public string $text, public bool $interrupted = false)
    {
    }
}
