# Provider HTTP testing

- Keep regular QA offline. Run `castor test --filter=ConfiguredModelAgentRunnerCassetteTest` for the public agent/provider boundary.
- Select a reviewed native Symfony AI cassette per scenario. Use `record: false` with no live client; retain full request matching and deterministic inputs.
- Use `castor llm:cassette:record` only for deliberate capture on configured `llama_cpp/flash`. It stages under `var/` and does not promote fixtures. Review secrets and local data before copying anything into the repository. See [provider HTTP cassettes](../../../../docs/llm-replay.md).
- Use smaller conversion or runtime tests when provider HTTP is not part of the contract. Offline replay is not evidence of live connectivity.
