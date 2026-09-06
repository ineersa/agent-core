<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Schema;

use Ineersa\CodingAgent\Config\BashToolConfig;
use Symfony\AI\Platform\Contract\JsonSchema\Provider\SchemaProviderInterface;

/**
 * Runtime fragment for the bash `timeout` property schema.
 *
 * Feeds the provider-visible description and `maximum` bound from the same
 * BashToolConfig instance the bash tool and its BashTimeoutMax constraint
 * consume, so schema and runtime validation cannot drift.
 */
final readonly class BashTimeoutSchemaProvider implements SchemaProviderInterface
{
    public function __construct(
        private BashToolConfig $config,
    ) {
    }

    public function getSchemaFragment(array $context = []): array
    {
        return [
            'description' => \sprintf(
                'Timeout in seconds (default: %d, max: %d). Provide an explicit higher value for commands that need more than the default.',
                $this->config->defaultTimeoutSeconds,
                $this->config->maxTimeoutSeconds,
            ),
            'maximum' => $this->config->maxTimeoutSeconds,
        ];
    }
}
