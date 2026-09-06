---
name: jbcontext-semantic-search
description: "Semantic code search with Hatfield code_search (jbcontext). Use when the relevant file or subsystem is unknown and you need meaning-based discovery before local reads."
version: 1.0.4
---

# Semantic code search

Use Hatfield `code_search` for meaning-based discovery when you do not already know the relevant file, class, or symbol.

## Guidelines

1. Read these guidelines before the first `code_search` call for a discovery question.
2. Call once with focused non-whitespace `text` (a question or representative snippet).
3. Optionally narrow once with `path_filter` set to a project-relative directory or file from the best first hit, for example `src/` or `src/CodingAgent/Runtime/Controller`. Absolute paths and `..` are rejected by the tool.
4. Verify promising hits with local `read` (and nearby code) before another semantic query.
5. Treat `similarity` as ranking, not probability or confidence.
6. Empty results mean no useful ranked hits for that query, not that the behavior is absent.
7. When `available: false`, relay the exact tool message and help the user act on it. Do not invent CLI flags. Do not repeat the same failing call.
8. Prefer IDE definition/references or direct reads once you know the symbol or path. Do not use `code_search` to review an existing diff.

Restarting the same Hatfield conversation after fixing CLI access or creating a manual index is enough; a brand-new session is not required.

## Examples

Broad discovery:

```text
code_search text="How does Hatfield prevent two processes from running the same session concurrently?"
```

One narrowed follow-up after a useful directory appears:

```text
code_search text="How does Hatfield prevent two processes from running the same session concurrently?" path_filter="src/CodingAgent/Runtime/Controller"
```

## Troubleshooting

| Tool message / state | What to do |
|---|---|
| Exact JB Context error text | Show the user that message. Help them fix CLI access, login, or index as the text indicates. |
| Still checking / pending | Wait for eligibility, or ask the user to restart Hatfield if it never leaves pending. |
| Disabled / no index | Ask the user to run `jbcontext index --project-path <project>` once when needed, then restart the same conversation. |
| Empty `results` | Try one different focused query or one `path_filter` narrow. Then fall back to local search/read. |
