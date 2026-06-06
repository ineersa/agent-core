# Research OpenAI Codex auth flow for Hatfield

## Goal
Investigate how pan/pi-mono implement OpenAI Codex OAuth/PKCE auth, token storage, refresh flow, account-id extraction, and overall auth process so Hatfield can add a user-auth-ready Codex provider. Include whether an existing Symfony/PHP OAuth PKCE library could replace a fully hand-rolled implementation.

## Acceptance criteria
- Document pan/pi-mono Codex OAuth/PKCE flow with exact files, classes, and command flow
- Document token creation, refresh, persistence format, and account-id extraction
- Identify candidate Symfony/PHP OAuth/PKCE libraries suitable for a local CLI auth flow and assess fit
- Recommend Hatfield implementation shape and follow-up task scope for auth:codex, refresh, and auth.json storage

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-06T17:25:53.157Z
