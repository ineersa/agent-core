<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Immutable execution model/reasoning snapshot for an agent child run.
 *
 * AppConfig-layer value type so {@see SessionAwareModelResolver} can consume
 * child RunStarted identity without depending on the AppExtension DTO.
 */
final readonly class ChildRunModelSnapshot
{
    public function __construct(
        public string $model,
        public ?string $reasoning = null,
    ) {
    }
}
