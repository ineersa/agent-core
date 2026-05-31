<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * Doctrine lifecycle trait that sets createdAt on insert and bumps
 * updatedAt on every insert/update via ORM lifecycle callbacks.
 *
 * The owning entity must declare #[ORM\HasLifecycleCallbacks].
 *
 * Timestamps are ISO 8601 strings for SQLite compatibility.
 */
#[ORM\HasLifecycleCallbacks]
trait TimestampableLifecycleTrait
{
    #[ORM\PrePersist]
    public function onPrePersistTimestamp(): void
    {
        $now = Clock::get()->now()->format('c');

        if ('' === $this->createdAt) {
            $this->createdAt = $now;
        }

        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdateTimestamp(): void
    {
        $this->updatedAt = Clock::get()->now()->format('c');
    }
}
