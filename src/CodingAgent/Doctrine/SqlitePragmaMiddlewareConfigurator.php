<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Doctrine;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects the configured Monolog logger into SqlitePragmaMiddleware after
 * container compilation is complete.
 *
 * RATIONALE
 * ─────────
 * Doctrine DBAL middleware is instantiated during container compilation.
 * Injecting LoggerInterface directly as a constructor or setter dependency
 * into SqlitePragmaMiddleware creates a compilation cycle:
 *   EntityManager → Connection → Middleware → Logger → MonologHandler
 *   → … → EntityManager
 *
 * This subscriber breaks the cycle by:
 *   1. Depending only on LoggerInterface (not on the middleware).
 *   2. Firing on ConsoleEvents::COMMAND, which is dispatched AFTER the
 *      container has fully compiled and the kernel has booted.
 *   3. Calling SqlitePragmaMiddleware::setGlobalLogger() with the real
 *      logger, which all middleware instances consult through
 *      getEffectiveLogger() before falling back to their instance logger.
 *
 * This ensures PRAGMA failure diagnostics use the project's configured
 * logging infrastructure (Monolog → HatfieldRotatingLogHandler → JSONL
 * log files) instead of error_log() or silent NullLogger drops.
 *
 * @see SqlitePragmaMiddleware::getEffectiveLogger()
 * @see SqlitePragmaMiddleware::setGlobalLogger()
 */
final class SqlitePragmaMiddlewareConfigurator implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Set the global logger on SqlitePragmaMiddleware before any command
     * accesses the database connection.
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        SqlitePragmaMiddleware::setGlobalLogger($this->logger);
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => 'onConsoleCommand'];
    }
}
