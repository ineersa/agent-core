<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Revolt\EventLoop;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Owns session-broker process-group isolation and supervised controller lifetime.
 *
 * Runs inside the actual `sqlite-queue:broker` process before BrokerCommand
 * creates Amp storage workers:
 * - posix_setsid() isolates the broker/helper group without an external setsid binary
 * - for controller-supervised launches only, a Revolt stdin watch treats controller
 *   hard-death EOF as SIGTERM so durable queue lifetime locks release
 *
 * Supervised ownership is detected from the existing session queue env published
 * by SessionSqliteQueueEnv. Standalone `sqlite-queue:broker --database=...` keeps
 * normal vendor stdin behavior (including /dev/null). Final vendor BrokerCommand
 * remains untouched.
 */
final class SessionSqliteQueueBrokerOwnerSubscriber implements EventSubscriberInterface
{
    private static bool $ownerWatchArmed = false;

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onConsoleCommand', 0],
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if (null === $command || 'sqlite-queue:broker' !== $command->getName()) {
            return;
        }

        $this->isolateOwnedProcessGroup();
        if ($this->isControllerSupervisedLaunch()) {
            $this->armOwnerStdinWatch();
        }
    }

    private function isolateOwnedProcessGroup(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            return;
        }

        if (!\function_exists('posix_setsid') || !\function_exists('posix_getpgid') || !\function_exists('posix_getpid')) {
            throw new \RuntimeException('Session sqlite-queue broker requires posix_setsid to isolate the Amp SQLite driver process group.');
        }

        $pid = posix_getpid();
        $pgid = posix_getpgid($pid);
        if (false !== $pgid && $pgid === $pid) {
            return;
        }

        if (posix_setsid() < 0) {
            throw new \RuntimeException('Session sqlite-queue broker failed to create an isolated process group with posix_setsid().');
        }
    }

    private function isControllerSupervisedLaunch(): bool
    {
        $database = $_SERVER[SessionSqliteQueueEnv::DATABASE_PATH]
            ?? $_ENV[SessionSqliteQueueEnv::DATABASE_PATH]
            ?? getenv(SessionSqliteQueueEnv::DATABASE_PATH);
        $endpoint = $_SERVER[SessionSqliteQueueEnv::ENDPOINT_PATH]
            ?? $_ENV[SessionSqliteQueueEnv::ENDPOINT_PATH]
            ?? getenv(SessionSqliteQueueEnv::ENDPOINT_PATH);

        return \is_string($database) && '' !== $database
            && \is_string($endpoint) && '' !== $endpoint;
    }

    private function armOwnerStdinWatch(): void
    {
        if (self::$ownerWatchArmed) {
            return;
        }
        if (!\defined('STDIN') || !\is_resource(\STDIN)) {
            throw new \RuntimeException('Controller-supervised sqlite-queue broker requires an open STDIN pipe owned by the controller.');
        }

        stream_set_blocking(\STDIN, false);
        self::$ownerWatchArmed = true;

        EventLoop::onReadable(\STDIN, static function (string $watcherId, mixed $stream): void {
            if (!\is_resource($stream)) {
                EventLoop::cancel($watcherId);
                self::requestOwnedShutdown();

                return;
            }

            $chunk = @fread($stream, 8192);
            if (false === $chunk || '' === $chunk) {
                EventLoop::cancel($watcherId);
                self::requestOwnedShutdown();
            }
        });
    }

    private static function requestOwnedShutdown(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('posix_getpid')) {
            return;
        }

        @posix_kill(posix_getpid(), \SIGTERM);
    }
}
