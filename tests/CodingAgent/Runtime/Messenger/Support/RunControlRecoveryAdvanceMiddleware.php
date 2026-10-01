<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger\Support;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Test-only middleware for supervised crash recovery proofs.
 *
 * First delivery for a marker path intentionally OOMs after Doctrine claim.
 * Replacement delivery falls through the real handler stack so production
 * AdvanceRunHandler commits the canonical turn under shared ready state.
 *
 * @internal
 */
final class RunControlRecoveryAdvanceMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $marker = getenv('HATFIELD_TEST_RUN_CONTROL_OOM_ONCE_PATH');
        $message = $envelope->getMessage();
        if (!\is_string($marker) || '' === $marker || !$message instanceof AdvanceRun) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (!is_file($marker)) {
            $dir = \dirname($marker);
            if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create OOM marker directory: '.$dir);
            }
            if (false === file_put_contents($marker, (string) getmypid())) {
                throw new \RuntimeException('Unable to write OOM marker: '.$marker);
            }
            // Worker process already set memory_limit=128M. Exhaust it so PHP
            // fatals while the Doctrine claim is still held.
            $blob = [];
            while (true) {
                $blob[] = str_repeat('x', 4 * 1024 * 1024);
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
