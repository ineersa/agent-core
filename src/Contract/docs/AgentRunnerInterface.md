# AgentRunnerInterface

**File:** `AgentRunnerInterface.php`  
**Namespace:** `Ineersa\AgentCore\Contract`

## Purpose

The primary public API for the agent runner. All operations on a run flow through this interface.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `start` | `start(StartRunInput $input): RunHandle` | Create and start a new agent run. Returns the run handle. |
| `continue` | `continue(string $runId): void` | Resume a paused/waiting run. |
| `steer` | `steer(string $runId, AgentMessage $message): void` | Inject a steering message to redirect agent behavior. |
| `followUp` | `followUp(string $runId, AgentMessage $message): void` | Append a follow-up message to the run context. |
| `cancel` | `cancel(string $runId, ?string $reason = null): void` | Request cooperative cancellation of a running agent. |
| `answerHuman` | `answerHuman(string $runId, string $questionId, mixed $answer): void` | Respond to an interrupt-style `ask_user` tool call. |

## Dependencies

- `Domain\Run\StartRunInput` — input DTO for starting a run
- `Domain\Run\RunHandle` — lightweight run reference
- `Domain\Message\AgentMessage` — message envelope

## Implementations

- `Application\Orchestrator\AgentRunner`
