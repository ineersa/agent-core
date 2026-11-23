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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

use function Amp\async;

final class Hatfield360Extension implements HatfieldExtensionInterface, TuiSessionObserverInterface, TuiRunObserverInterface, TuiSessionCloseObserverInterface, TuiBackgroundJobObserverInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    // /reload replaces the extension instance, not the explicit launch.
    private static int $sequence = 0;
    private ?StatusClient $client = null;
    private bool $canBootstrap = false;
    private string $sessionId = '';
    private int $epoch = 0;
    private bool $closing = false;
    /** @var array{id: string, status: string}|null */
    private ?array $turn = null;
    /** @var array<string, array{id: string, reason: string}> */
    private array $waits = [];
    /** @var array<string, array<string, array{id: string, reason: string}>> */
    private array $childWaits = [];
    /** @var array<string, string> */
    private array $tools = [];
    /** @var array<string, true>|null */
    private ?array $scripts = [];
    /** @var array<string, int>|null */
    private ?array $actors = [];
    /** @var Future<void>|null */
    private ?Future $drain = null;
    /** @var array{string, string, int, int, array<string, mixed>}|null */
    private ?array $pending = null;

    public function register(ExtensionApiInterface $api): void
    {
        $this->client = StatusClient::fromEnvironment();
        $bootstrap = getenv('H360_STATUS_BOOTSTRAP_TOKEN');
        $this->canBootstrap = 'Linux' === \PHP_OS_FAMILY && false !== getenv('H360_STATUS_ENDPOINT')
            && false === getenv('H360_STATUS_TOKEN') && false === getenv('H360_STATUS_LAUNCH_ID')
            && \is_string($bootstrap) && 1 === preg_match('/\A[0-9a-f]{64}\z/', $bootstrap);
    }

    public function onTuiSessionSelected(string $sessionId, bool $resumed = false): void
    {
        if ($this->closing || (null === $this->client && !$this->canBootstrap)) {
            return;
        }

        $this->sessionId = $sessionId;
        $this->epoch = ++self::$sequence;
        $this->turn = null;
        $this->waits = [];
        $this->childWaits = [];
        $this->tools = [];
        // Attach/resume cannot reconstruct starts that happened before subscription.
        $this->scripts = $resumed ? null : [];
        $this->actors = $resumed ? null : [];
        $this->snapshot($this->epoch);
    }

    public function onTuiSessionClosing(string $sessionId): void
    {
        if ($this->closing || '' === $sessionId || $this->sessionId !== $sessionId) {
            return;
        }
        $this->closing = true;
        $this->pending = null;
        // Quit must not initiate manual authorization.
        if (null === $this->client) {
            return;
        }
        $this->enqueue('session.ended', ++self::$sequence, []);
        try {
            $this->drain?->await(new TimeoutCancellation(0.65));
        } catch (CancelledException) {
            $this->pending = null;
            $this->logDrop($sessionId);
        }
    }

    public function onTuiRunObserved(TuiRunObservationDTO $observation): void
    {
        if (!$this->observes($observation->sessionId)) {
            return;
        }
        $type = $observation->type;
        if (null !== $observation->childRunId) {
            if (!$this->observeChild($observation, $observation->childRunId)) {
                return;
            }
        } elseif (TuiRunObservationTypeEnum::TurnStarted === $type) {
            // Internal model/tool steps stay in the same logical response.
            if ('working' === ($this->turn['status'] ?? null)) {
                return;
            }
            $this->turn = ['id' => (string) (self::$sequence + 1), 'status' => 'working'];
            $this->waits = [];
            $this->tools = [];
        } elseif (null !== $this->turn && 'working' === $this->turn['status']) {
            $outcome = match ($type) {
                TuiRunObservationTypeEnum::TurnCompleted => 'completed',
                TuiRunObservationTypeEnum::TurnFailed => 'failed',
                TuiRunObservationTypeEnum::TurnCancelled => 'cancelled',
                default => null,
            };
            if (null !== $outcome) {
                $this->turn['status'] = $outcome;
                $this->waits = [];
                $this->tools = [];
            } elseif (null !== $observation->requestId) {
                $key = hash('sha256', $observation->requestId);
                if (TuiRunObservationTypeEnum::ToolStarted === $type && null !== $observation->toolName
                    && mb_check_encoding($observation->toolName, 'UTF-8') && mb_strlen($observation->toolName, 'UTF-8') <= 128
                    && !isset($this->tools[$key]) && \count($this->tools) < 32) {
                    $this->tools[$key] = $observation->toolName;
                } elseif (TuiRunObservationTypeEnum::ToolFinished === $type && isset($this->tools[$key])) {
                    unset($this->tools[$key]);
                } elseif (TuiRunObservationTypeEnum::QuestionRequested === $type || TuiRunObservationTypeEnum::ApprovalRequested === $type) {
                    if (isset($this->waits[$key]) || \count($this->waits) >= 16) {
                        return;
                    }
                    $this->waits[$key] = ['id' => (string) (self::$sequence + 1), 'reason' => TuiRunObservationTypeEnum::ApprovalRequested === $type ? 'permission' : 'question'];
                } elseif (TuiRunObservationTypeEnum::InputResolved === $type && isset($this->waits[$key])) {
                    unset($this->waits[$key]);
                } else {
                    return;
                }
            } else {
                return;
            }
        } else {
            return;
        }
        $this->snapshot(++self::$sequence);
    }

    public function onTuiBackgroundJobObserved(TuiBackgroundJobObservationDTO $observation): void
    {
        if (!$this->observes($observation->sessionId) || null === $this->scripts) {
            return;
        }
        $id = hash('sha256', $observation->ownerRunId."\0".$observation->jobId);
        if (TuiBackgroundJobObservationTypeEnum::Started === $observation->type) {
            if (isset($this->scripts[$id])) {
                return;
            }
            if (\count($this->scripts) >= 1024) {
                $this->scripts = null;
            } else {
                $this->scripts[$id] = true;
            }
        } elseif (isset($this->scripts[$id])) {
            unset($this->scripts[$id]);
        } else {
            return;
        }
        $this->snapshot(++self::$sequence);
    }

    private function observeChild(TuiRunObservationDTO $observation, string $id): bool
    {
        $type = $observation->type;
        $start = $this->actors[$id] ?? null;
        if (TuiRunObservationTypeEnum::ChildExecutionStarted === $type && null !== $this->actors) {
            if (null !== $start) {
                return false;
            }
            if (\count($this->actors) >= 1024) {
                $this->actors = null;
            } else {
                $this->actors[$id] = $observation->sequence;
            }

            return true;
        }
        if (null !== $start && $observation->sequence <= $start) {
            return false;
        }
        if (TuiRunObservationTypeEnum::ChildExecutionEnded === $type) {
            $observed = null !== $start || isset($this->childWaits[$id]);
            unset($this->actors[$id], $this->childWaits[$id]);

            return $observed;
        }
        if (null === $observation->requestId || (null === $start && null !== $this->actors)) {
            return false;
        }
        $key = $observation->requestId;
        if (TuiRunObservationTypeEnum::InputResolved === $type && isset($this->childWaits[$id][$key])) {
            unset($this->childWaits[$id][$key]);
            if ([] === $this->childWaits[$id]) {
                unset($this->childWaits[$id]);
            }

            return true;
        }
        if (TuiRunObservationTypeEnum::QuestionRequested !== $type && TuiRunObservationTypeEnum::ApprovalRequested !== $type) {
            return false;
        }
        if (isset($this->childWaits[$id][$key]) || \count($this->childWaits[$id] ?? []) >= 16
            || (!isset($this->childWaits[$id]) && \count($this->childWaits) >= 1024)) {
            return false;
        }
        $this->childWaits[$id][$key] = ['id' => (string) (self::$sequence + 1), 'reason' => TuiRunObservationTypeEnum::ApprovalRequested === $type ? 'permission' : 'question'];

        return true;
    }

    private function observes(string $sessionId): bool
    {
        return !$this->closing && '' !== $this->sessionId && $sessionId === $this->sessionId;
    }

    private function snapshot(int $sequence): void
    {
        // Prefer foreground input, then the oldest observed child episode.
        $attention = $this->waits[array_key_first($this->waits) ?? ''] ?? null;
        if (null === $attention) {
            foreach ($this->childWaits as $waits) {
                $candidate = $waits[array_key_first($waits) ?? ''] ?? null;
                if (null !== $candidate && (null === $attention || (int) $candidate['id'] < (int) $attention['id'])) {
                    $attention = $candidate;
                }
            }
        }
        $this->enqueue('session.snapshot', $sequence, [
            'provider' => 'hatfield', 'turn' => $this->turn, 'attention' => $attention,
            'activeSubagents' => null === $this->actors ? null : \count($this->actors),
            'backgroundScripts' => null === $this->scripts ? null : \count($this->scripts), 'tool' => $this->tools[array_key_last($this->tools) ?? ''] ?? null,
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function enqueue(string $kind, int $sequence, array $fields): void
    {
        // A newer complete observation supersedes any unsent snapshot.
        $this->pending = [$kind, $this->sessionId, $sequence, $this->epoch, $fields];
        $this->drain ??= async(function (): void {
            try {
                while (null !== $this->pending) {
                    [$kind, $sessionId, $sequence, $epoch, $fields] = $this->pending;
                    $this->pending = null;
                    $client = $this->client ??= ($this->closing ? null : HatfieldProcessIdentityProvider::register());
                    if ($this->epoch !== $epoch || ($this->closing && 'session.snapshot' === $kind)) {
                        continue;
                    }
                    if (null === $client || !$client->send($kind, $sessionId, $sequence, $epoch, $fields)) {
                        $this->logDrop($sessionId);
                    }
                }
            } catch (\Throwable) {
                $this->logDrop($this->sessionId);
                $this->pending = null;
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
