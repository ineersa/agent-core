<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

use Amp\CancelledException;
use Amp\Future;
use Amp\TimeoutCancellation;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundJobObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiBackgroundJobObservationTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiBackgroundJobObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionCloseObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionObserverInterface;
use Ineersa\HatfieldExt\Hatfield360\DTO\SessionCheckpointDTO;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Uid\Uuid;

use function Amp\async;

final class Hatfield360Extension implements HatfieldExtensionInterface, TuiSessionObserverInterface, TuiRunObserverInterface, TuiSessionCloseObserverInterface, TuiBackgroundJobObserverInterface, LoggerAwareInterface
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
    /** @var array<string, array{start: int, ending: bool}> */
    private array $scripts = [];
    /** @var array<string, array{native: int, start: int, parent: string, end: ?int, turn: ?int, last: int, waits: array<string, int>}> */
    private array $actors = [];
    /** @var array<string, int> */
    private array $confirmedActors = [];
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
        $this->scripts = [];
        $this->actors = [];
        $this->confirmedActors = [];
        // Previous unsent work no longer describes the selected session.
        $this->pending = [];
        $this->checkpoint = new SessionCheckpointDTO($sessionId, $this->epoch, (string) Uuid::v7(), 'hatfield', ['waits', 'session-end', 'scripts', 'actors']);
        $this->scheduleDrain();
    }

    public function onTuiSessionClosing(string $sessionId): void
    {
        if ($this->closing || '' === $sessionId || $this->sessionId !== $sessionId) {
            return;
        }
        $this->closing = true;
        $this->scripts = [];
        $this->actors = [];
        $this->confirmedActors = [];
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

        if (null !== $observation->childRunId) {
            $this->observeChild($observation);

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

    public function onTuiBackgroundJobObserved(TuiBackgroundJobObservationDTO $observation): void
    {
        if ($this->closing || '' === $this->sessionId || $observation->sessionId !== $this->sessionId) {
            return;
        }

        $id = hash('sha256', $observation->ownerRunId."\0".$observation->jobId);
        if (TuiBackgroundJobObservationTypeEnum::Started === $observation->type) {
            if (isset($this->scripts[$id])) {
                return;
            }
            if (\count($this->scripts) >= 32) {
                $this->logDrop($this->sessionId);

                return;
            }
            $start = ++self::$sequence;
            if ($this->enqueue('script.started', $start, ['scriptId' => $id])) {
                $this->scripts[$id] = ['start' => $start, 'ending' => false];
            }

            return;
        }

        $script = $this->scripts[$id] ?? null;
        if (null === $script || $script['ending']) {
            return;
        }
        if ($this->enqueue('script.ended', ++self::$sequence, ['scriptId' => $id, 'scriptStart' => $script['start']])) {
            $this->scripts[$id]['ending'] = true;
        } else {
            unset($this->scripts[$id]);
        }
    }

    private function observeChild(TuiRunObservationDTO $observation): void
    {
        if (null === $observation->childRunId || null === $observation->parentRunId || $observation->childRunId === $this->sessionId || $observation->sequence <= 0) {
            return;
        }
        $id = hash('sha256', $observation->childRunId);
        $parentId = $observation->parentRunId === $this->sessionId ? 'main' : hash('sha256', $observation->parentRunId);
        $actor = $this->actors[$id] ?? null;
        if (TuiRunObservationTypeEnum::ChildExecutionStarted === $observation->type) {
            if (null !== $actor && (null === $actor['end'] || $observation->sequence <= $actor['end'])) {
                return;
            }
            $parent = $this->actors[$parentId] ?? null;
            $parentStart = 'main' === $parentId ? $this->epoch : (null === ($parent['end'] ?? null) ? ($parent['start'] ?? null) : null);
            if (null === $parentStart || $id === $parentId) {
                return;
            }
            // The session registration already occupies the main actor slot.
            if (null === $actor && \count($this->actors) >= 31) {
                $this->logDrop($this->sessionId);

                return;
            }
            $start = ++self::$sequence;
            if ($this->enqueue('actor.started', $start, ['actorId' => $id, 'parentId' => $parentId, 'parentStart' => $parentStart])) {
                $this->actors[$id] = ['native' => $observation->sequence, 'start' => $start, 'parent' => $parentId, 'end' => null, 'turn' => null, 'last' => $observation->sequence - 1, 'waits' => []];
            }

            return;
        }
        if (null === $actor || $actor['parent'] !== $parentId || null !== $actor['end'] || $observation->sequence <= $actor['last']) {
            return;
        }
        if (TuiRunObservationTypeEnum::ChildExecutionEnded !== $observation->type) {
            $this->observeChildTurn($id, $observation);

            return;
        }
        if ($observation->sequence <= $actor['native']) {
            return;
        }
        if (null !== $actor['turn']) {
            $this->enqueue('turn.ended', ++self::$sequence, $this->childTurnFields($id, $actor['start'], $actor['turn']) + ['outcome' => 'unknown']);
        }
        if ($this->enqueue('actor.ended', ++self::$sequence, ['actorId' => $id, 'actorStart' => $actor['start']])) {
            $this->actors[$id]['end'] = $observation->sequence;
        } else {
            unset($this->actors[$id]);
        }
    }

    private function observeChildTurn(string $id, TuiRunObservationDTO $observation): void
    {
        $actor = &$this->actors[$id];
        if ($observation->sequence < $actor['native']) {
            return;
        }
        $actor['last'] = $observation->sequence;
        if (TuiRunObservationTypeEnum::TurnStarted === $observation->type) {
            if (null === $actor['turn']) {
                $turn = ++self::$sequence;
                $fields = $this->childTurnFields($id, $actor['start'], $turn);
                if ($this->enqueue('turn.started', $turn, array_diff_key($fields, ['turnStart' => true]))) {
                    $actor['turn'] = $turn;
                    $this->enqueue('activity.changed', ++self::$sequence, $fields + ['activity' => 'working']);
                }
            }

            return;
        }
        if (null === $actor['turn'] || null === $observation->requestId) {
            return;
        }
        $fields = $this->childTurnFields($id, $actor['start'], $actor['turn']);
        $waitId = $observation->requestId;
        if (TuiRunObservationTypeEnum::InputResolved === $observation->type) {
            if (isset($actor['waits'][$waitId])) {
                $this->enqueue('wait.resolved', ++self::$sequence, $fields + ['waitId' => $waitId, 'waitStart' => $actor['waits'][$waitId]]);
                unset($actor['waits'][$waitId]);
            }
        } elseif (!isset($actor['waits'][$waitId]) && \count($actor['waits']) < 16) {
            $start = ++self::$sequence;
            if ($this->enqueue('wait.started', $start, $fields + ['waitId' => $waitId, 'reason' => TuiRunObservationTypeEnum::ApprovalRequested === $observation->type ? 'permission' : 'question'])) {
                $actor['waits'][$waitId] = $start;
            }
        }
    }

    /** @return array{actorId: string, actorStart: int, turnId: string, turnStart: int} */
    private function childTurnFields(string $id, int $actorStart, int $turn): array
    {
        return ['actorId' => $id, 'actorStart' => $actorStart, 'turnId' => (string) $turn, 'turnStart' => $turn];
    }

    /** @return array{actorId: string, actorStart: int, turnId: string} */
    private function turnFields(int $turn): array
    {
        return ['actorId' => 'main', 'actorStart' => $this->epoch, 'turnId' => (string) $turn];
    }

    /** @param array<string, mixed> $fields */
    private function enqueue(string $kind, int $sequence, array $fields): bool
    {
        if (\count($this->pending) >= self::MAX_PENDING) {
            $this->logDrop($this->sessionId);

            return false;
        }
        $this->pending[] = [$kind, $this->sessionId, $sequence, $this->epoch, $fields];
        $this->scheduleDrain();

        return true;
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
                    $this->scripts = [];
                    $this->actors = [];
                    $this->confirmedActors = [];

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
                            $this->scripts = [];
                            $this->actors = [];
                            $this->confirmedActors = [];

                            return;
                        }
                        $this->checkpoint = null;
                    }
                    $event = array_shift($this->pending);
                    if (null === $event) {
                        return;
                    }
                    [$kind, $sessionId, $sequence, $epoch, $fields] = $event;
                    $scriptStart = 'script.started' === $kind;
                    $scriptEnd = 'script.ended' === $kind;
                    $id = ($scriptStart || $scriptEnd) ? (string) $fields['scriptId'] : '';
                    if ($scriptEnd && ($this->scripts[$id]['start'] ?? null) !== $fields['scriptStart']) {
                        continue;
                    }
                    $actorStart = 'actor.started' === $kind;
                    $actorEnd = 'actor.ended' === $kind;
                    $actorId = ($actorStart || $actorEnd) ? (string) $fields['actorId'] : '';
                    if (!$actorStart && !$actorEnd && isset($fields['actorId']) && 'main' !== $fields['actorId']
                        && ($this->confirmedActors[(string) $fields['actorId']] ?? null) !== $fields['actorStart']) {
                        continue;
                    }
                    if ($actorEnd && ($this->confirmedActors[$actorId] ?? null) !== $fields['actorStart']) {
                        continue;
                    }
                    if ($actorStart && (isset($this->confirmedActors[$actorId]) || \count($this->confirmedActors) >= 31
                        || ('main' !== $fields['parentId'] && ($this->confirmedActors[(string) $fields['parentId']] ?? null) !== $fields['parentStart']))) {
                        $sent = false;
                    } else {
                        $sent = $client->send($kind, $sessionId, $sequence, $epoch, $fields, requireApplied: $scriptStart || $actorStart || $actorEnd);
                    }
                    if (!$sent) {
                        $this->logDrop($sessionId);
                    }
                    // A failed start cannot authorize an end. Do not mutate a
                    // new session's map if selection changed while I/O yielded.
                    if ($this->epoch === $epoch && ($scriptEnd || ($scriptStart && !$sent))) {
                        unset($this->scripts[$id]);
                    }
                    if ($this->epoch === $epoch && ($actorStart || $actorEnd)) {
                        if ($actorStart && $sent) {
                            $this->confirmedActors[$actorId] = $sequence;
                        } elseif ($actorEnd && $sent) {
                            unset($this->confirmedActors[$actorId]);
                        }
                        if (($actorEnd || !$sent) && ($this->actors[$actorId]['start'] ?? null) === ($actorStart ? $sequence : $fields['actorStart'])) {
                            unset($this->actors[$actorId]);
                        }
                    }
                }
            } catch (\Throwable) {
                $this->logDrop($this->sessionId);
                $this->pending = [];
                $this->scripts = [];
                $this->actors = [];
                $this->confirmedActors = [];
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
