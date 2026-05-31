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
Status: DONE
Branch: task/ext-00-extension-api-contracts-boundary
Worktree: /home/ineersa/projects/agent-core-worktrees/ext-00-extension-api-contracts-boundary
Fork run: rvecjxx62y81
PR URL: https://github.com/ineersa/agent-core/pull/47
PR Status: merged
Started: 2026-05-25T20:58:23.513Z
Completed: 2026-05-25T21:06:55.573Z

## Work log
- Created: 2026-05-22T22:43:01.641Z

## Task workflow update - 2026-05-25T20:58:23.513Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ext-00-extension-api-contracts-boundary.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ext-00-extension-api-contracts-boundary.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ext-00-extension-api-contracts-boundary.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ext-00-extension-api-contracts-boundary.

## Task workflow update - 2026-05-25T20:58:58.551Z
- Recorded fork run: rvecjxx62y81
- Launched implementation fork rvecjxx62y81 in /home/ineersa/projects/agent-core-worktrees/ext-00-extension-api-contracts-boundary.

## Task workflow update - 2026-05-25T21:03:33.122Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ext-00-extension-api-contracts-boundary to origin.
- branch 'task/ext-00-extension-api-contracts-boundary' set up to track 'origin/task/ext-00-extension-api-contracts-boundary'.
- Created PR: https://github.com/ineersa/agent-core/pull/47
- Validation: castor deptrac: OK (0 violations, 325 uncovered, 729 allowed); vendor/bin/phpunit tests/CodingAgent/ExtensionApi/ExtensionApiContractsTest.php: OK (10 tests, 25 assertions; PHPUnit notice only); php bin/console list: OK container compiles; composer dump-autoload + class load check: OK; castor cs-fix --path src/CodingAgent/ExtensionApi/ and tests/CodingAgent/ExtensionApi/: applied formatting; castor cs-check --path src/CodingAgent/ExtensionApi/ tests/CodingAgent/ExtensionApi/: OK
- Summary: Implemented EXT-00 in worktree /home/ineersa/projects/agent-core-worktrees/ext-00-extension-api-contracts-boundary and committed dc406b07 on task/ext-00-extension-api-contracts-boundary. Added public Extension API boundary under src/CodingAgent/ExtensionApi/ using namespace Ineersa\Hatfield\ExtensionApi: HatfieldExtensionInterface, ExtensionApiInterface, ToolRegistrationDTO. Added Composer PSR-4 mapping and excluded ExtensionApi from CodingAgent service autodiscovery to avoid namespace mismatch. Added focused contract tests. Boundary remains PHP-native/API-local only; no AgentCore/CodingAgent internals/TUI/Symfony dependencies.

## Task workflow update - 2026-05-25T21:06:55.573Z
- Moved CODE-REVIEW → DONE.
- Merged task/ext-00-extension-api-contracts-boundary into integration checkout.
- Merge made by the 'ort' strategy.
 composer.json                                      |   1 +
 config/services.yaml                               |   1 +
 .../ExtensionApi/ExtensionApiInterface.php         |  28 ++++
 .../ExtensionApi/HatfieldExtensionInterface.php    |  22 +++
 .../ExtensionApi/ToolRegistrationDTO.php           |  37 +++++
 .../ExtensionApi/ExtensionApiContractsTest.php     | 166 +++++++++++++++++++++
 6 files changed, 255 insertions(+)
 create mode 100644 src/CodingAgent/ExtensionApi/ExtensionApiInterface.php
 create mode 100644 src/CodingAgent/ExtensionApi/HatfieldExtensionInterface.php
 create mode 100644 src/CodingAgent/ExtensionApi/ToolRegistrationDTO.php
 create mode 100644 tests/CodingAgent/ExtensionApi/ExtensionApiContractsTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/ext-00-extension-api-contracts-boundary.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Summary: PR #47 was merged on GitHub; moving EXT-00 to DONE and syncing integration checkout.
