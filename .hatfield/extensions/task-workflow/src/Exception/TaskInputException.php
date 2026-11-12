<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Exception;

final class TaskInputException extends \InvalidArgumentException
{
    /** @param list<array{path:string,message:string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Task input validation failed: '.implode('; ', array_map(static fn (array $error): string => $error['path'].': '.$error['message'], $this->errors)));
    }
}
