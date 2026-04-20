<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Schema;

/**
 * Defines supported payload schema versions and the current default used for serialized command and event contracts.
 */
final class SchemaVersion
{
    public const string V1 = '1.0';

    public const string CURRENT = self::V1;
}
