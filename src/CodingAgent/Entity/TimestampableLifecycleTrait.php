<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * Doctrine lifecycle trait that sets createdAt on insert and bumps
 * updatedAt on every insert/update via ORM lifecycle callbacks.
 *
 * The owning entity must declare #[ORM\HasLifecycleCallbacks] and
 * own public \DateTimeImmutable $createdAt and $updatedAt fields.
 *
 * Timestamps are DateTimeImmutable for proper Doctrine datetime
 * handling (datetime_immutable column type).
 */
trait TimestampableLifecycleTrait
{
    #[ORM\PrePersist]
    public function onPrePersistTimestamp(): void
    {
        $now = Clock::get()->now();

        if (!isset($this->createdAt)) {
            $this->createdAt = $now;
        }

        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdateTimestamp(): void
    {
        $this->updatedAt = Clock::get()->now();
    }
}
