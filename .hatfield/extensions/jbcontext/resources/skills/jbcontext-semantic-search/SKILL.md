---
name: jbcontext-semantic-search
description: "Semantic code search with Hatfield code_search (jbcontext). Use when the relevant file or subsystem is unknown and you need meaning-based discovery before local reads."
version: 1.0.5
---

# Semantic code search

Examples and troubleshooting for Hatfield `code_search`. Follow the tool prompt guidelines for the discovery workflow.

## Examples

Broad discovery, then local verify:

```text
code_search text="How does Hatfield prevent two processes from running the same session concurrently?"
```

Useful hits pointed at `src/CodingAgent/Runtime/Controller`. Read nearby files there. If ranking is still noisy, one narrowed follow-up:

```text
code_search text="How does Hatfield prevent two processes from running the same session concurrently?" path_filter="src/CodingAgent/Runtime/Controller"
```

That directory filter returned the lock-key and ownership methods first. Snippets can be incomplete, so confirm the local source before changing code.

## Troubleshooting

| Tool message / state | What to do |
|---|---|
| Exact JB Context error text | Show the user that message. Help them fix CLI access or index as the text indicates. Do not invent flags. |
| Still checking / pending | Wait for eligibility. If it never leaves pending, ask the user to restart Hatfield. |
| Disabled / no index | Ask the user to run `jbcontext index --project-path <project>` once when needed, then restart the same conversation. A brand-new session is not required. |
| Empty `results` | Try one different focused query or one `path_filter` narrow. Empty means no useful ranked hits for that query, not that the behavior is absent. Then fall back to local search/read. |
| Unavailable after a prior failure | Use other tools or the reported guidance. Do not repeat the same failing call. |
