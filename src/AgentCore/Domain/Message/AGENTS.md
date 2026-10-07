# Messages

- Keep payloads immutable and infrastructure-agnostic.
- Let Application and CodingAgent own dispatch and handling. Use `config/packages/messenger.yaml` for routing.
- Only fork and subagent calls may carry `ToolLaunchInputReferenceDTO`; ordinary tool calls keep `launchContext=null`.
- Keep filesystem paths out of launch references. Do not embed child-launch conversation bodies in Messenger or batch snapshots.
- Preserve the launch reference when adding a human-input answer.
