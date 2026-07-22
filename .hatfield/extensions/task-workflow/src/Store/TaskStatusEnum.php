<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Store;

enum TaskStatusEnum: string
{
    case TODO = 'TODO';
    case IN_PROGRESS = 'IN-PROGRESS';
    case CODE_REVIEW = 'CODE-REVIEW';
    case DONE = 'DONE';
    case ARCHIVE = 'ARCHIVE';
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
        if ('ARCHIVE' === $upper) {
            return self::ARCHIVE;
        }
        if ('CANCELLED' === $upper || 'CANCELED' === $upper) {
            return self::CANCELLED;
        }

        throw new \RuntimeException('Unknown task status: '.$value);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::TODO,
            self::IN_PROGRESS,
            self::CODE_REVIEW,
            self::DONE,
            self::ARCHIVE,
            self::CANCELLED,
        ];
    }

    /**
     * Default task_list statuses: active + cancelled, omitting ARCHIVE unless requested.
     *
     * @return list<self>
     */
    public static function defaultListed(): array
    {
        return [
            self::TODO,
            self::IN_PROGRESS,
            self::CODE_REVIEW,
            self::DONE,
            self::CANCELLED,
        ];
    }
}
