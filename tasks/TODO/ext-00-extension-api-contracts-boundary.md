# EXT-00 Extension API contracts and boundary

## Goal
Plan: `.pi/plans/extension-api-phar-plan.md`

Create the v1 public extension API boundary inside the monorepo without splitting a Composer package yet. The API must live in `src/CodingAgent/ExtensionApi/` but use namespace `Ineersa\Hatfield\ExtensionApi` so future extraction to `ineersa/hatfield-extension-api` does not break downstream extensions.

This task is the prerequisite for EXT-01 and EXT-02.

## Acceptance criteria
- `src/CodingAgent/ExtensionApi/` contains the initial public contracts/value objects: `HatfieldExtensionInterface`, `ExtensionApiInterface`, and `ToolRegistrationDTO` as designed in the plan.
- `ToolRegistrationDTO` models extension-provided permanent tools with name, provider/schema description, parameters JSON schema, handler reference, optional prompt summary, and prompt guidelines; it does not expose dynamic-tool APIs or a tool scope enum.
- Composer autoload maps `Ineersa\Hatfield\ExtensionApi\` to `src/CodingAgent/ExtensionApi/`.
- `depfile.yaml` contains/keeps an `AppExtensionApi` layer with no allowed dependencies on other project layers.
- `AGENTS.md` documents the Extension API boundary and extraction-safety rules.
- Extension API code uses only PHP-native types and API-local DTOs/enums/interfaces; it does not depend on AgentCore, CodingAgent internals, TUI, Symfony, settings, runtime, registry, or PHAR packaging code.
- Validation: `castor deptrac` passes.

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
- Created: 2026-05-22T22:43:01.641Z
