<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Schema;

use Symfony\AI\Platform\Contract\JsonSchema\Provider\SchemaProviderInterface;

final readonly class ScheduleArgumentsSchemaProvider implements SchemaProviderInterface
{
    public function getSchemaFragment(array $context = []): array
    {
        return ['type' => 'object', 'additionalProperties' => true];
    }
}
