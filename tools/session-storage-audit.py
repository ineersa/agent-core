#!/usr/bin/env python3
"""Read-only, privacy-safe canonical JSONL storage measurement.

Usage:
  tools/session-storage-audit.py /absolute/path/to/.hatfield
  tools/session-storage-audit.py --project-final /absolute/path/to/.hatfield

Output is deliberately limited to fixed schema labels, approved field paths, and
numeric aggregates. It never prints paths, identifiers, payload values, hashes,
or malformed input text. The tool does not write below its input root.
"""

from __future__ import annotations

import argparse
import json
import os
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any, Iterable

CURRENT_TYPES = frozenset({
    "tool_execution_start", "tool_execution_update", "tool_execution_end", "agent_end",
    "run_started", "turn_advanced", "llm_step_completed", "llm_step_failed",
    "llm_step_aborted", "waiting_human", "agent_command_applied",
    "agent_command_rejected", "agent_command_queued", "tool_batch_committed",
    "model_notification", "context_compaction_requested", "context_compaction_started",
    "context_compacted", "context_compaction_failed", "history_position_set",
    "history_tail_discarded",
})
REMOVED_TYPES = frozenset({
    "agent_start", "turn_start", "message_start", "message_update", "message_end",
    "turn_end", "model_changed", "agent_command_superseded", "stale_result_ignored",
    "tool_call_result_received",
})
DEFAULT_FIELD_PATHS = (
    "payload.tool_result", "payload.tool_result.result", "payload.result",
    "payload.message", "payload.message.content", "payload.message.details",
    "payload.assistant_message", "payload.assistant_message.content",
    "payload.text", "payload.tool_calls_count", "payload.messages",
    "payload.system_prompt", "payload.metadata",
)


def encoded_bytes(value: Any) -> int:
    """Bytes of canonical compact UTF-8 JSON for one value, including descendants."""
    return len(json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))


def canonical_line_bytes(event: dict[str, Any]) -> int:
    """Projected schema line bytes: compact UTF-8 JSON envelope plus one LF."""
    return encoded_bytes(event) + 1


def scope_for(path: Path) -> str:
    return "child" if "artifacts" in path.parts and "agents" in path.parts else "parent"


def event_paths(root: Path) -> Iterable[Path]:
    sessions = root / "sessions"
    if not sessions.is_dir():
        return []
    return (path for directory, _, names in os.walk(sessions) for name in names
            if name == "events.jsonl" for path in (Path(directory, name),))


def state_paths(root: Path) -> Iterable[Path]:
    sessions = root / "sessions"
    if not sessions.is_dir():
        return []
    return (path for directory, _, names in os.walk(sessions) for name in names
            if name == "state.json" for path in (Path(directory, name),))


def percentile(values: list[int], percent: int) -> int:
    if not values:
        return 0
    ordered = sorted(values)
    return ordered[(len(ordered) - 1) * percent // 100]


def stable_type(raw: Any) -> str:
    if raw in CURRENT_TYPES or raw in REMOVED_TYPES:
        return str(raw)
    return "unsupported"


def path_value(event: dict[str, Any], field_path: str) -> tuple[bool, Any]:
    value: Any = event
    for segment in field_path.split("."):
        if not isinstance(value, dict) or segment not in value:
            return False, None
        value = value[segment]
    return True, value


def legacy_tool_identity(event: dict[str, Any]) -> tuple[str, int, str] | None:
    payload = event.get("payload")
    if not isinstance(payload, dict):
        return None
    tool_call_id = payload.get("tool_call_id")
    if not isinstance(tool_call_id, str):
        return None
    turn_no = event.get("turn_no")
    run_id = event.get("run_id")
    if not isinstance(turn_no, int) or not isinstance(run_id, str):
        return None
    return run_id, turn_no, tool_call_id


def projected_event(event: dict[str, Any]) -> dict[str, Any] | None:
    """Project independently transformable final-schema rows.

    Legacy text-only tool ends deliberately return unchanged here; their exact
    final typed envelope requires the associated legacy message_end result and
    is handled by project_file() using canonical run/turn/tool-call identity.
    """
    raw_type = event.get("type")
    if raw_type in REMOVED_TYPES:
        return None
    if raw_type not in CURRENT_TYPES:
        return None
    projected = dict(event)
    payload = projected.get("payload")
    if raw_type == "llm_step_completed" and isinstance(payload, dict):
        payload = dict(payload)
        payload.pop("text", None)
        payload.pop("tool_calls_count", None)
        projected["payload"] = payload
    return projected


def typed_from_legacy_end(end: dict[str, Any], message_end: dict[str, Any]) -> dict[str, Any] | None:
    """Deterministically reconstruct a final ToolCallResult only when complete.

    The old schema omitted operation identity/error from tool_execution_end.
    A projection is therefore counted as unprojectable unless a message_end
    supplies a complete normalized ToolCallResult-shaped source under
    payload.message.details.tool_result. This intentionally refuses lossy
    guesses and never exposes the identity used for the internal join.
    """
    end_payload = end.get("payload")
    message_payload = message_end.get("payload")
    if not isinstance(end_payload, dict) or not isinstance(message_payload, dict):
        return None
    message = message_payload.get("message")
    if not isinstance(message, dict):
        return None
    details = message.get("details")
    if not isinstance(details, dict):
        return None
    source = details.get("tool_result")
    if not isinstance(source, dict):
        return None
    required = {"run_id", "turn_no", "step_id", "attempt", "idempotency_key", "tool_call_id", "order_index", "result", "is_error", "error", "pending_human_input"}
    if not required.issubset(source):
        return None
    identity = legacy_tool_identity(end)
    if identity is None or source["run_id"] != identity[0] or source["turn_no"] != identity[1] or source["tool_call_id"] != identity[2]:
        return None
    projected = dict(end)
    projected["payload"] = {"tool_result": source}
    return projected


class Totals:
    def __init__(self, field_paths: tuple[str, ...]) -> None:
        self.files = Counter[str]()
        self.records = Counter[str]()
        self.line_bytes = Counter[str]()
        self.types: dict[tuple[str, str], list[int]] = defaultdict(lambda: [0, 0])
        self.fields: dict[tuple[str, str, str], list[int]] = defaultdict(lambda: [0, 0])
        self.malformed = Counter[str]()
        self.unsupported = Counter[str]()
        self.field_paths = field_paths
        self.projected_records = Counter[str]()
        self.projected_bytes = Counter[str]()
        self.projected_types: dict[tuple[str, str], list[int]] = defaultdict(lambda: [0, 0])
        self.projected_fields: dict[tuple[str, str, str], list[int]] = defaultdict(lambda: [0, 0])
        self.removed_records = Counter[str]()
        self.removed_bytes = Counter[str]()
        self.unprojectable_tool_ends = Counter[str]()
        self.unprojectable_tool_end_source_bytes = Counter[str]()
        self.state_files = Counter[str]()
        self.state_bytes: dict[str, list[int]] = defaultdict(list)
        self.state_malformed = Counter[str]()
        self.operational_rows: dict[tuple[str, str], int] = Counter()
        self.operational_bytes: dict[tuple[str, str], int] = Counter()
        self.operational_unsupported: dict[tuple[str, str], int] = Counter()

    def add(self, scope: str, event: dict[str, Any], raw_line_bytes: int) -> None:
        event_type = stable_type(event.get("type"))
        self.records[scope] += 1
        self.line_bytes[scope] += raw_line_bytes
        if event_type == "unsupported":
            self.unsupported[scope] += 1
        row = self.types[(scope, event_type)]
        row[0] += 1
        row[1] += raw_line_bytes
        for field_path in self.field_paths:
            exists, value = path_value(event, field_path)
            if exists:
                field = self.fields[(scope, event_type, field_path)]
                field[0] += 1
                field[1] += encoded_bytes(value)


def project_file(events: list[tuple[dict[str, Any], int]], scope: str, totals: Totals) -> None:
    legacy_messages: dict[tuple[str, int, str], dict[str, Any]] = {}
    for event, _ in events:
        if event.get("type") == "message_end":
            identity = legacy_tool_identity(event)
            if identity is not None:
                legacy_messages[identity] = event
    for event, raw_line_bytes in events:
        raw_type = event.get("type")
        if raw_type in REMOVED_TYPES:
            totals.removed_records[scope] += 1
            continue
        projected = projected_event(event)
        if projected is None:
            continue
        if raw_type == "tool_execution_end":
            payload = projected.get("payload")
            if isinstance(payload, dict) and not isinstance(payload.get("tool_result"), dict):
                identity = legacy_tool_identity(projected)
                rebuilt = typed_from_legacy_end(projected, legacy_messages.get(identity, {})) if identity is not None else None
                if rebuilt is None:
                    totals.unprojectable_tool_ends[scope] += 1
                    totals.unprojectable_tool_end_source_bytes[scope] += raw_line_bytes
                    continue
                projected = rebuilt
        projected_bytes = canonical_line_bytes(projected)
        projected_type = stable_type(projected.get("type"))
        totals.projected_records[scope] += 1
        totals.projected_bytes[scope] += projected_bytes
        type_totals = totals.projected_types[(scope, projected_type)]
        type_totals[0] += 1
        type_totals[1] += projected_bytes
        for field_path in totals.field_paths:
            exists, value = path_value(projected, field_path)
            if exists:
                field_totals = totals.projected_fields[(scope, projected_type, field_path)]
                field_totals[0] += 1
                field_totals[1] += encoded_bytes(value)


def add_legacy_state(totals: Totals, scope: str, decoded: Any) -> None:
    if not isinstance(decoded, dict) or not isinstance(decoded.get("runId"), str) or not isinstance(decoded.get("status"), str):
        totals.operational_unsupported[(scope, "state")] += 1
        return
    run_id = decoded["runId"]
    state_fields = ("runId", "status", "turnNo", "activeStepId", "lastSeq", "version")
    totals.operational_rows[(scope, "state")] += 1
    totals.operational_bytes[(scope, "state")] += 38 + sum(len(str(decoded[field]).encode("utf-8")) for field in state_fields if decoded.get(field) is not None)
    tool_calls = decoded.get("currentToolCalls", [])
    if not isinstance(tool_calls, list):
        totals.operational_unsupported[(scope, "tool")] += 1
    else:
        for tool in tool_calls:
            required = ("batchId", "toolCallId", "orderIndex", "status", "attempt")
            if not isinstance(tool, dict) or any(field not in tool for field in required):
                totals.operational_unsupported[(scope, "tool")] += 1
                continue
            totals.operational_rows[(scope, "tool")] += 1
            totals.operational_bytes[(scope, "tool")] += 38 + len(run_id.encode("utf-8")) + sum(len(str(tool[field]).encode("utf-8")) for field in required if tool[field] is not None)
    human_inputs = decoded.get("pendingHumanInputRequests", [])
    if not isinstance(human_inputs, list):
        totals.operational_unsupported[(scope, "human")] += 1
    else:
        for index, request in enumerate(human_inputs):
            if not isinstance(request, dict) or not isinstance(request.get("questionId"), str) or not isinstance(request.get("continuationKind"), str):
                totals.operational_unsupported[(scope, "human")] += 1
                continue
            tool_call_id = request.get("continuationRef", {}).get("tool_call_id") if isinstance(request.get("continuationRef"), dict) else None
            totals.operational_rows[(scope, "human")] += 1
            values = (run_id, request["questionId"], index, request["continuationKind"], tool_call_id, "waiting")
            totals.operational_bytes[(scope, "human")] += 38 + sum(len(str(value).encode("utf-8")) for value in values if value is not None)


def audit(root: Path, field_paths: tuple[str, ...], project_final: bool) -> Totals:
    totals = Totals(field_paths)
    for path in state_paths(root):
        scope = scope_for(path)
        totals.state_files[scope] += 1
        try:
            raw = path.read_bytes()
            totals.state_bytes[scope].append(len(raw))
            add_legacy_state(totals, scope, json.loads(raw))
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            totals.state_malformed[scope] += 1
    for path in event_paths(root):
        scope = scope_for(path)
        totals.files[scope] += 1
        events: list[tuple[dict[str, Any], int]] = []
        try:
            with path.open("rb") as handle:
                for line in handle:
                    if not line.strip():
                        continue
                    try:
                        decoded = json.loads(line)
                    except (UnicodeDecodeError, json.JSONDecodeError):
                        totals.malformed[scope] += 1
                        continue
                    if not isinstance(decoded, dict):
                        totals.malformed[scope] += 1
                        continue
                    totals.add(scope, decoded, len(line))
                    if project_final:
                        events.append((decoded, len(line)))
        except OSError:
            totals.malformed[scope] += 1
            continue
        if project_final:
            project_file(events, scope, totals)
    return totals


def ratio(numerator: int, denominator: int) -> str:
    return "0.000000" if denominator == 0 else f"{numerator / denominator:.6f}"


def output(totals: Totals, project_final: bool) -> None:
    print("SCHEMA audit=3 privacy=aggregate-only")
    print("ACCOUNTING line_bytes=raw_jsonl_line_including_lf value_bytes=compact_utf8_json_inclusive overlap=additive fields=selected_only malformed=excluded")
    print("OPERATIONAL_BOUND row=state max_varchar_bytes=1562 timestamps_bytes=38 sqlite_utf8_length_caveat=declared_char_limits_not_file_allocation")
    print("OPERATIONAL_BOUND row=tool max_varchar_bytes=797 timestamps_bytes=38 sqlite_utf8_length_caveat=declared_char_limits_not_file_allocation")
    print("OPERATIONAL_BOUND row=human max_varchar_bytes=829 timestamps_bytes=38 sqlite_utf8_length_caveat=declared_char_limits_not_file_allocation")
    for scope in ("parent", "child"):
        print(f"SCOPE scope={scope} files={totals.files[scope]} records={totals.records[scope]} line_bytes={totals.line_bytes[scope]} malformed={totals.malformed[scope]} unsupported={totals.unsupported[scope]}")
        sizes = totals.state_bytes[scope]
        print(f"STATE_JSON scope={scope} files={totals.state_files[scope]} total_bytes={sum(sizes)} p50_bytes={percentile(sizes, 50)} p95_bytes={percentile(sizes, 95)} max_bytes={max(sizes, default=0)} malformed={totals.state_malformed[scope]}")
        for row_kind in ("state", "tool", "human"):
            print(f"OPERATIONAL scope={scope} row={row_kind} rows={totals.operational_rows[(scope, row_kind)]} logical_scalar_bytes={totals.operational_bytes[(scope, row_kind)]} unsupported_shapes={totals.operational_unsupported[(scope, row_kind)]}")
    for (scope, event_type), (records, bytes_) in sorted(totals.types.items()):
        print(f"EVENT scope={scope} type={event_type} records={records} line_bytes={bytes_}")
    for (scope, event_type, field_path), (records, bytes_) in sorted(totals.fields.items()):
        print(f"FIELD scope={scope} type={event_type} path={field_path} present={records} value_bytes={bytes_}")
    if project_final:
        for (scope, event_type), (records, bytes_) in sorted(totals.projected_types.items()):
            print(f"PROJECTED_EVENT scope={scope} type={event_type} records={records} line_bytes={bytes_}")
        for (scope, event_type, field_path), (records, bytes_) in sorted(totals.projected_fields.items()):
            print(f"PROJECTED_FIELD scope={scope} type={event_type} path={field_path} present={records} value_bytes={bytes_}")
        for scope in ("parent", "child"):
            source = totals.line_bytes[scope]
            projected = totals.projected_bytes[scope]
            unprojectable = totals.unprojectable_tool_end_source_bytes[scope]
            comparable_source = source - unprojectable
            saved = comparable_source - projected
            print(f"PROJECTION scope={scope} projected_records={totals.projected_records[scope]} projected_line_bytes={projected} comparable_source_line_bytes={comparable_source} saved_line_bytes={saved} retained_ratio={ratio(projected, comparable_source)} removed_events={totals.removed_records[scope]} unprojectable_tool_ends={totals.unprojectable_tool_ends[scope]} unprojectable_source_line_bytes={unprojectable}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Read-only privacy-safe Hatfield canonical event storage audit")
    parser.add_argument("hatfield_root", type=Path, help="explicit existing directory named .hatfield")
    parser.add_argument("--field-path", action="append", choices=DEFAULT_FIELD_PATHS, help="approved nested field path; repeatable")
    parser.add_argument("--project-final", action="store_true", help="calculate deterministic final-schema byte projection")
    args = parser.parse_args()
    root = args.hatfield_root.expanduser().resolve()
    if root.name != ".hatfield" or not root.is_dir():
        parser.error("hatfield_root must be an existing directory named .hatfield")
    field_paths = tuple(args.field_path or DEFAULT_FIELD_PATHS)
    output(audit(root, field_paths, args.project_final), args.project_final)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
