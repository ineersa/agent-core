---
builtin: true
description: Bundled AI provider catalog, user copy, providers:update, and settings overlay.
---

# AI Catalog

Hatfield ships a curated AI provider catalog: connection settings plus a small
model list per known provider (`zai`, `deepseek`, `openai-codex`, `grok-cli`).
The file is frozen in the install (`version: 1` today). Runtime never downloads
models.dev; only `hatfield providers:update` does, and only for metadata on
models you already have.

## Precedence

```
bundled config/ai-catalog.yaml (ver N)
        │  first run: byte-copy
        ▼
~/.hatfield/ai-catalog.yaml          ◀── providers:update writes here
        │  lowest layer (catalog folded under defaults)
        ▼
overlay:
  catalog  <  hatfield.defaults.yaml
           <  ~/.hatfield/settings.yaml
           <  <project>/.hatfield/settings.yaml
        │
        ▼
effective AiConfig → model picker / forks

models.dev ── only via `hatfield providers:update` ──▶
  • metadata deltas on EXISTING catalog model ids
    (context_window, max_tokens, input, reasoning, tool_calling, cost)
  • NEW upstream ids → printed hints only (never auto-added)
  • whitelist never touches base_url / api / paths / thinking_level_map
```

Settings win over the catalog. An explicit provider `models:` map in settings
**replaces** the catalog list wholesale (not a deep merge). Prefer sparse
settings (`enabled`, `api_key`) and use `ai.favorite_models` for a lean picker.

## Where each field comes from

| Concern | Source |
|---|---|
| Connection (`base_url`, `api`, paths, quirks, auth command) | Bundled catalog; changes only with Hatfield releases |
| Curated model presence + `thinking_level_map` | Bundled catalog (seed); user-added models survive rebase |
| Cost / context / max tokens / modalities / reasoning / tool_calling | models.dev via `hatfield providers:update` (existing ids only) |
| Enable, API keys, `models:` trim/extend, favorites, default model | Settings overlay |

## `hatfield providers:update`

1. Ensures `~/.hatfield/ai-catalog.yaml` exists (bootstrap copy if missing).
2. **Rebase** onto the bundled default: shared providers take the default’s
   connection fields and model list; model ids that exist only in your copy are
   kept; providers that exist only in your copy are kept whole. Version becomes
   the bundled version. (Deleting a bundled model from your copy does not stick —
   rebase restores it.)
3. **Sync** fetches `https://models.dev/api.json` and refreshes allowlisted
   metadata on matching model ids. Unknown upstream ids are listed as
   `available upstream (not added): …`.
4. Atomic write (`0600`). Offline / HTTP / JSON failures soft-fail (exit 0) and
   leave the file untouched.

Never taken from models.dev: `base_url`, `api`, paths, auth, compatibility,
`thinking_level_map`, or any new model id.

## Version skew

If a newer Hatfield release ships a higher catalog `version` than your user
copy, the TUI startup loaded-resources header shows:

```text
⚠ AI Catalog: update available — run `hatfield providers:update`
```

Press Ctrl+R to expand the loaded-resources detail. Resolve by running the
command (rebases + syncs, then clears the skew).

## Adding a model by hand

Catalog presence is curated. New upstream ids from `providers:update` are hints
only. To use one:

1. Copy a full model block under the provider’s `models:` map in
   `~/.hatfield/settings.yaml` (or project settings).
2. **Warning:** adding `models:` replaces the catalog list for that provider
   entirely. Include every model id you still want selectable, not only the new
   one — or keep settings sparse and instead append the model under
   `~/.hatfield/ai-catalog.yaml` (user-only ids survive rebase).

Favorites (`ai.favorite_models`) only quick-pick; they do not invent models.

## Paths

| Path | Role |
|---|---|
| `config/ai-catalog.yaml` | Bundled default (inside binary / install) |
| `~/.hatfield/ai-catalog.yaml` | User catalog (bootstrap + update target) |
| `~/.hatfield/settings.yaml` | User settings overlay |
| `<project>/.hatfield/settings.yaml` | Project settings overlay |

Related: [settings.md](settings.md), [settings-models.md](settings-models.md).
