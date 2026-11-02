<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

final readonly class ToolInvocationResultDTO
{
    /**
     * @param list<array<string,mixed>> $content
     * @param list<OutputReferenceDTO>  $outputs
     */
    public function __construct(public string $invocationId, public string $toolName, public bool $isError, public bool $cancelled, public array $content, public string $text, public array $outputs = [])
    {
    }
}
