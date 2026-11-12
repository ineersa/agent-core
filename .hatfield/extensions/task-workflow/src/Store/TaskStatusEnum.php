<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Store;

enum TaskStatusEnum: string
{
    case TODO = 'TODO';
    case IN_PROGRESS = 'IN-PROGRESS';
    case CODE_REVIEW = 'CODE-REVIEW';
    case DONE = 'DONE';
    case CANCELLED = 'CANCELLED';

    public static function fromMixed(string $value): self
    {
        $upper = strtoupper($value);
        if ('TODO' === $upper) {
            return self::TODO;
        }
        if ('IN_PROGRESS' === $upper || 'INPROGRESS' === $upper || 'IN-PROGRESS' === $upper) {
            return self::IN_PROGRESS;
        }
        if ('CODE_REVIEW' === $upper || 'CODEREVIEW' === $upper || 'CODE-REVIEW' === $upper) {
            return self::CODE_REVIEW;
        }
        if ('DONE' === $upper) {
            return self::DONE;
        }
        if ('CANCELLED' === $upper || 'CANCELED' === $upper) {
            return self::CANCELLED;
        }

        throw new \RuntimeException('Unknown task status: '.$value);
    }
}
