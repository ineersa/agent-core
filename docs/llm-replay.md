# LLM Replay Fixtures

Deterministic provider/LLM regression without live model calls.

## Purpose

Replay fixtures record HTTP/provider traffic so controller and unit tests can assert
LLM-visible flows offline. Live `castor test:llm-real` remains opt-in for compatibility smoke.

## Commands

```bash
castor test                 # default suites exclude live llm-real
castor test:controller-replay
castor llm:fixtures:info
castor llm:fixtures:record  # re-record from live LLM intentionally
castor test:llm-real        # live smoke (llama-proxy / test model)
```

## Rules

- Replay proves fixture parity, not always live correctness. If a hang reproduces live but not in replay, trust live reproduction.
- Fixture paths and env seams stay in test DI (`APP_ENV=test`) — production code must not branch on replay env vars.
- Unique first user prompts per live scenario when using llama-proxy cache normalization.

## Related

- Testing skill: `.agents/skills/testing/SKILL.md`
- TUI testing: [tui-testing.md](tui-testing.md)
