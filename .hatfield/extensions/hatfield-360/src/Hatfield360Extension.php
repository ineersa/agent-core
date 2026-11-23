<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

use Amp\CancelledException;
use Amp\Future;
use Amp\TimeoutCancellation;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionCloseObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionObserverInterface;
use Ineersa\HatfieldExt\Hatfield360\DTO\SessionCheckpointDTO;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Uid\Uuid;

use function Amp\async;

final class Hatfield360Extension implements HatfieldExtensionInterface, TuiSessionObserverInterface, TuiRunObserverInterface, TuiSessionCloseObserverInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const int MAX_PENDING = 32;
    // /reload replaces the extension instance, not the explicit launch.
    private static int $sequence = 0;
    private ?StatusClient $client = null;
    private bool $canBootstrap = false;
    private string $sessionId = '';
    private int $epoch = 0;
    private ?int $turn = null;
    /** @var array<string, int> */
    private array $waits = [];
    private bool $closing = false;
    /** @var Future<void>|null */
    private ?Future $drain = null;
    private ?SessionCheckpointDTO $checkpoint = null;
    /** @var list<array{string, string, int, int, array<string, mixed>}> */
    private array $pending = [];

    public function register(ExtensionApiInterface $api): void
    {
        $this->client = StatusClient::fromEnvironment();
        $bootstrap = getenv('H360_STATUS_BOOTSTRAP_TOKEN');
        $this->canBootstrap = 'Linux' === \PHP_OS_FAMILY && false !== getenv('H360_STATUS_ENDPOINT')
            && false === getenv('H360_STATUS_TOKEN') && false === getenv('H360_STATUS_LAUNCH_ID')
            && \is_string($bootstrap) && 1 === preg_match('/\A[0-9a-f]{64}\z/', $bootstrap);
    }

    public function onTuiSessionSelected(string $sessionId): void
    {
        if ($this->closing || (null === $this->client && !$this->canBootstrap)) {
            return;
        }

        $this->sessionId = $sessionId;
        $this->epoch = ++self::$sequence;
        $this->turn = null;
        $this->waits = [];
        // Previous unsent work no longer describes the selected session.
        $this->pending = [];
        $this->checkpoint = new SessionCheckpointDTO($sessionId, $this->epoch, (string) Uuid::v7(), 'hatfield', ['waits', 'session-end']);
        $this->scheduleDrain();
    }

    public function onTuiSessionClosing(string $sessionId): void
    {
        if ($this->closing || '' === $sessionId || $this->sessionId !== $sessionId) {
            return;
        }
        $this->closing = true;
        // Drop historical work rather than spending one socket deadline per queued
        // event. An in-flight frame keeps its original order before the final frame.
        $this->pending = [];
        if (null === $this->client) {
            // Quit must not start or wait for a manual authorization exchange.
            $this->checkpoint = null;

            return;
        }
        $this->enqueue('session.ended', ++self::$sequence, []);
        try {
            $this->drain?->await(new TimeoutCancellation(0.65));
        } catch (CancelledException) {
            $this->pending = [];
            $this->checkpoint = null;
            $this->logDrop($sessionId);
        }
    }

    public function onTuiRunObserved(TuiRunObservationDTO $observation): void
    {
        if ($this->closing || '' === $this->sessionId || $observation->sessionId !== $this->sessionId) {
            return;
        }

        if (TuiRunObservationTypeEnum::TurnStarted === $observation->type) {
            // Hatfield may advance an internal model/tool step before the same
            // foreground run settles. Keep one protocol turn through that work.
            if (null !== $this->turn) {
                return;
            }
            $this->turn = ++self::$sequence;
            $this->waits = [];
            $this->enqueue('turn.started', $this->turn, $this->turnFields($this->turn));
            $this->enqueue('activity.changed', ++self::$sequence, $this->turnFields($this->turn) + ['turnStart' => $this->turn, 'activity' => 'working']);

            return;
        }

        if (null === ($turn = $this->turn)) {
            return;
        }

        $fields = $this->turnFields($turn) + ['turnStart' => $turn];
        $outcome = match ($observation->type) {
            // The foreground stream cannot prove that background work settled.
            TuiRunObservationTypeEnum::TurnCompleted => 'unknown',
            TuiRunObservationTypeEnum::TurnFailed => 'failed',
            TuiRunObservationTypeEnum::TurnCancelled => 'cancelled',
            default => null,
        };
        if (null !== $outcome) {
            $this->turn = null;
            $this->waits = [];
            $this->enqueue('turn.ended', ++self::$sequence, $fields + ['outcome' => $outcome]);

            return;
        }

        if (null === $observation->requestId) {
            return;
        }
        $approval = TuiRunObservationTypeEnum::ApprovalRequested === $observation->type;
        // A tool approval can be answered through the same human-input route
        // as a question. Only its request ID is guaranteed on the answer.
        $id = hash('sha256', $observation->requestId);
        if (TuiRunObservationTypeEnum::QuestionRequested === $observation->type || TuiRunObservationTypeEnum::ApprovalRequested === $observation->type) {
            if (isset($this->waits[$id]) || \count($this->waits) >= 16) {
                return;
            }
            $sequence = $this->waits[$id] = ++self::$sequence;
            $this->enqueue('wait.started', $sequence, $fields + ['waitId' => $id, 'reason' => $approval ? 'permission' : 'question']);
        } elseif (isset($this->waits[$id])) {
            $this->enqueue('wait.resolved', ++self::$sequence, $fields + ['waitId' => $id, 'waitStart' => $this->waits[$id]]);
            unset($this->waits[$id]);
        }
    }

    /** @return array{actorId: string, actorStart: int, turnId: string} */
    private function turnFields(int $turn): array
    {
        return ['actorId' => 'main', 'actorStart' => $this->epoch, 'turnId' => (string) $turn];
    }

    /** @param array<string, mixed> $fields */
    private function enqueue(string $kind, int $sequence, array $fields): void
    {
        if (\count($this->pending) >= self::MAX_PENDING) {
            $this->logDrop($this->sessionId);

            return;
        }
        $this->pending[] = [$kind, $this->sessionId, $sequence, $this->epoch, $fields];
        $this->scheduleDrain();
    }

    private function scheduleDrain(): void
    {
        if (null !== $this->drain) {
            return;
        }
        $this->drain = async(function (): void {
            try {
                $client = $this->client ??= ($this->closing ? null : HatfieldProcessIdentityProvider::register());
                if (null === $client) {
                    $this->logDrop($this->sessionId);
                    $this->pending = [];

                    return;
                }
                while (true) {
                    if (null !== ($checkpoint = $this->checkpoint)) {
                        $confirmed = $client->ensureSession($checkpoint);
                        if ($this->checkpoint !== $checkpoint) {
                            // A newer selection arrived while the socket yielded. Its
                            // higher sequence and fresh queue supersede this attempt.
                            continue;
                        }
                        if (!$confirmed) {
                            $this->logDrop($checkpoint->sessionId);
                            $this->pending = [];

                            return;
                        }
                        $this->checkpoint = null;
                    }
                    $event = array_shift($this->pending);
                    if (null === $event) {
                        return;
                    }
                    [$kind, $sessionId, $sequence, $epoch, $fields] = $event;
                    if (!$client->send($kind, $sessionId, $sequence, $epoch, $fields)) {
                        $this->logDrop($sessionId);
                    }
                }
            } catch (\Throwable) {
                $this->logDrop($this->sessionId);
                $this->pending = [];
            } finally {
                $this->drain = null;
            }
        });
    }

    private function logDrop(string $sessionId): void
    {
        $this->logger?->warning('hatfield_360.status_delivery_failed', [
            'component' => 'hatfield_360', 'event_type' => 'status_delivery_failed',
            'run_id' => $sessionId, 'session_id' => $sessionId,
        ]);
    }
}
