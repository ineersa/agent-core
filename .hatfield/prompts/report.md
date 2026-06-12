---
description: File a bug report or issue for the agent-core project
---

User reported issue:
```text
$ARGUMENTS
```

You are filing a bug report / issue for the `ineersa/agent-core` project. Follow these steps:

## 1. Clarify the Issue

If the issue description above is vague, missing reproduction steps, or lacks expected vs actual behavior, **ask the user clarifying questions now before filing**. Wait for their answers. Do not guess or fabricate details. Only proceed once you have enough information.

## 2. Collect Environment Diagnostics (best-effort)

Create a timestamped directory:
```bash
mkdir -p .hatfield/tmp/reports/$(date +%Y%m%d-%H%M%S)
```

### TUI Snapshot
`.hatfield/tmp/tui/snapshots` is where user taken snapshots are, check if there is recent/fresh snapshot from past 1-2 minutes. 
If not there, ask user to take one with C-a + S, after user will send continuation check a directory to get a snapshot file.

### Run Logs
Gather recent/current session logs if they exist (`.hatfield/sessions/*/events.jsonl`, `.hatfield/sessions/*/state.json`, `var/log/*.log` relevant lines, `var/reports/*`). **Redact aggressively**: strip API keys, tokens, passwords, raw LLM prompts/responses, environment variable listings, and any other secrets. 
Save sanitized copies into the timestamped directory.

### Session and Working Directory
- Session ID: check `HATFIELD_SESSION_ID` or list `.hatfield/sessions/` for the most recent.
- CWD: run `pwd`.
- Include both in the issue body.

## 3. Compose the Issue

**Title**: short, specific summary of the problem.

**Body**:
```markdown
## Description
...

## Steps to Reproduce
1.
2.
3.

## Expected Behavior
...

## Actual Behavior
...

## Environment
- Session ID: <id>
- CWD: <path>
- OS / terminal:
- Hatfield version (if known):
- Model / provider:
- Branch / worktree:  
- Running in tmux: yes / no
```

## 4. File the Issue

In order of preference:
1. **`gh issue create --repo ineersa/agent-core`** — if the GitHub CLI is installed and authenticated. Pipe or pass the title and body.

## 5. Include Diagnostic Artifacts

Include the **absolute paths** to any saved diagnostic files directly in the issue body. For example:

- Snapshot: `/absolute/path/to/.hatfield/tmp/reports/<ts>/snapshot.ansi`
- Logs: `/absolute/path/to/.hatfield/tmp/reports/<ts>/logs.txt`

If no snapshot or logs are available, state that explicitly in the issue. Do not upload raw secrets, API keys, or unredacted logs.

## 6. Final Response

After filing (or preparing the URL for the user), reply with:
- The GitHub issue URL (or prefilled URL if not yet submitted)
- Paths to saved local diagnostic files
- Any redactions you performed and why
