---
builtin: true
description: Bundled AI provider catalog, user copy, providers:update, and settings overlay.
---

# AI Catalog

Hatfield ships a curated AI provider catalog: connection settings plus a small
model list per known provider (`zai`, `deepseek`, `opencode-go`, `openai-codex`, `grok-cli`).
The bundled file is frozen in the install (`version: 16` at this writing; the
integer bumps when the bundled catalog changes). Known providers ship **with
definitions present and `enabled: false`**. Enabling a provider is a settings /
setup step, not an automatic first-run action. Runtime never downloads
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
    — except context_window for models pinned with
    compatibility.pin_context_window
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
| Cost / context / max tokens / modalities / reasoning / tool_calling | models.dev via `hatfield providers:update` (existing ids only; a `pin_context_window` model keeps its catalog context_window) |
| Enable, API keys, `models:` trim/extend, favorites, default model | Settings overlay |

## `hatfield providers:update`

1. Ensures `~/.hatfield/ai-catalog.yaml` exists (bootstrap copy if missing).
2. **Rebase** onto the bundled default: shared providers take the default’s
   connection fields and model list; model ids that exist only in your copy are
   kept; providers that exist only in your copy are kept whole. Version becomes
   the bundled version. (Deleting a bundled model from your copy does not stick —
   rebase restores it.)
3. **Sync** fetches `https://models.dev/api.json` and refreshes allowlisted
   metadata on matching model ids. A model with `compatibility.pin_context_window: true`
   keeps its catalog `context_window` — models.dev reports the raw model window,
   while curated caps encode real cost policy (GPT-6 and GPT-5.6 models are
   pinned to the 272k cheap pricing tier). Partial upstream `cost` maps refresh
   only the fields they provide and keep existing `cache_read` / `cache_write`
   values when those fields are absent. Explicit numeric zeros still apply.
   Every other metadata key still syncs. Unknown
   upstream ids are listed as `available upstream (not added): …`.
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

## Interactive enablement

`hatfield providers:setup` (or `bin/console providers:setup`) opens a standalone
setup TUI for enabling providers and wiring credentials. It is **manual**. First
catalog bootstrap only copies the bundled file to `~/.hatfield/ai-catalog.yaml`;
it does not enable providers or invent API keys. Sparse settings overlays such as
`ai.providers.zai: { enabled: true, api_key: env:ZAI_API_KEY }` remain the
non-interactive alternative.

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

## Cost estimates

Catalog `cost` values are Standard short-context API rates in USD per 1M tokens.
For GPT-6 and GPT-5.6 models, Hatfield pins `context_window` to 272000 so
estimates stay on that flat tier. Only the documented default 30m cache-write
rate is modeled. The 272k pin does not select or price 12h retention. ChatGPT
subscription sessions still show API-priced estimates, not billed subscription
charges.

Input token classes are billed once as
`ordinary = input_tokens - cache_read_tokens - cache_creation_tokens`, with
`cache_read_tokens` preferred over the `cached_tokens` alias when both are
present. Impossible counts are clamped to remaining input. Historical sessions
cannot recover `cache_write_tokens` that were never persisted.

Output cost uses inclusive `output_tokens`. Supported OpenResponses and Generic
Completions extractors already include reasoning in that total and expose
`thinking_tokens` only as detail, so Hatfield does not add thinking again.

Audit limits (OpenAI docs and models.dev checked 2026-10-23):

- Sources: [prompt caching](https://developers.openai.com/api/docs/guides/prompt-caching),
  [API pricing](https://developers.openai.com/api/docs/pricing),
  [gpt-5.6-luna](https://developers.openai.com/api/docs/models/gpt-5.6-luna),
  [gpt-5.6-sol](https://developers.openai.com/api/docs/models/gpt-5.6-sol),
  [gpt-5.6-terra](https://developers.openai.com/api/docs/models/gpt-5.6-terra).
- Exact official rates for `gpt-5.3-codex-spark` were not verified; the catalog
  keeps its prior values unchanged.
- Long-context premiums above 272k are not modeled. 12h retention and
  mixed-lifetime write pricing are outside the scope of these estimates.
