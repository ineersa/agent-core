<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * POC: holds pending fork child starts that are deferred by one tick cycle.
 *
 * ForkPocRoutingListener must not call $client->start() directly because
 * the Symfony TUI event loop renders only on the next tick() cycle.
 * Even though ForkPocRoutingListener creates a placeholder tab before
 * calling start(), no render happens until the listener returns to the
 * event loop and the tick fires. With sync:// transports (default in
 * dev mode), start() blocks for the entire first LLM turn, so the
 * placeholder is never painted.
 *
 * This store defers the blocking start() to the second tick after
 * enqueue, guaranteeing at least one processRender() call displays
 * the placeholder before the blocking call.
 *
 * Lifecycle:
 *   ForkPocRoutingListener::handle()  → enqueue()
 *   TickPollListener tick 1           → tick() → nothing ready (deferTicks > 0)
 *   Tui::tick() processRender()       → PLACEHOLDER VISIBLE ON SCREEN
 *   TickPollListener tick 2           → tick() → start() called, tab updated
 *   Tui::tick() processRender()       → child tab with real data visible
 *
 * @see https://github.com/symfony/symfony/blob/7.2/src/Symfony/Component/Tui/Tui.php#L223-L248
 *      Tui::tick() — processRender() runs BEFORE invokeTickCallback(),
 *      so the placeholder is rendered before the blocking start().
 */
final class ForkPocPendingStore
{
    private const int DEFER_TICKS = 2;

    /**
     * @var list<array{
     *     deferTicks: int,
     *     placeholderRunId: string,
     *     placeholderId: string,
     *     task: string,
     *     cwd: string,
     * }>
     */
    private array $pending = [];

    /**
     * Enqueue a pending fork start.
     *
     * Caller has already created the placeholder tab and added it to TabService.
     * The store will hold this for DEFER_TICKS ticks, then the TickPollListener
     * will call start() and update the tab with the real RunHandle.
     */
    public function enqueue(
        string $placeholderRunId,
        string $placeholderId,
        string $task,
        string $cwd,
    ): void {
        $this->pending[] = [
            'deferTicks' => self::DEFER_TICKS,
            'placeholderRunId' => $placeholderRunId,
            'placeholderId' => $placeholderId,
            'task' => $task,
            'cwd' => $cwd,
        ];
    }

    /**
     * Decrement defer counters and return items ready for start().
     *
     * Called on every tick from TickPollListener. Items with deferTicks > 0
     * are decremented and kept; items reaching 0 are returned for processing.
     *
     * @return list<array{
     *     placeholderRunId: string,
     *     placeholderId: string,
     *     task: string,
     *     cwd: string,
     * }>
     */
    public function tick(): array
    {
        if ([] === $this->pending) {
            return [];
        }

        $ready = [];
        $remaining = [];

        foreach ($this->pending as $item) {
            --$item['deferTicks'];

            if ($item['deferTicks'] <= 0) {
                $ready[] = $item;
            } else {
                $remaining[] = $item;
            }
        }

        $this->pending = $remaining;

        return $ready;
    }

    public function hasPending(): bool
    {
        return [] !== $this->pending;
    }
}
