# LLM Replay Fixtures

Deterministic provider/LLM regression without live model calls.
Visual runtime context: [`../architecture/index.html`](../architecture/index.html) when present.

## Purpose

Replay fixtures drive offline controller and unit proofs of LLM-visible flows.
Behavioral TUI/controller replay stays on source `bin/console` with test DI.
Live `castor test:llm-real` remains opt-in for compatibility smoke.

## Commands

```bash
castor test                 # default suites exclude live llm-real
castor test:controller-replay
castor llm:fixtures:info
castor test:llm-real        # live smoke (llama-proxy / test model)
```

Replay helpers live under `tests/AgentCore/Infrastructure/SymfonyAi/Replay/`
(`FixtureReplayModelClient`, `FixtureReplayResultConverter`). Committed fixtures
are maintained directly under `tests/AgentCore/Fixtures/traces/` and related
controller/TUI fixture dirs; there is no supported live recording Castor task.

## Rules

- Replay proves fixture parity, not always live correctness. If a hang reproduces live but not in replay, trust live reproduction.
- Fixture paths and env seams stay in test DI (`APP_ENV=test`) — production code must not branch on replay env vars.
- Unique first user prompts per live scenario when using llama-proxy cache normalization.

## Related

- Testing skill: `.agents/skills/testing/SKILL.md`
- TUI testing: [tui-testing.md](tui-testing.md)
