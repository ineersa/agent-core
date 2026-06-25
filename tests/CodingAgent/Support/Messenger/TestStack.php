<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Minimal {@see StackInterface} stub for Messenger middleware unit tests.
 *
 * Does nothing except return the envelope unchanged.
 */
final class TestStack implements StackInterface
{
    public function next(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $envelope;
            }
        };
    }
}
