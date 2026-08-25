#!/usr/bin/env python3
"""Read-only, privacy-safe structural audit for a Hatfield .hatfield directory.

Usage: tools/session-storage-audit.py /absolute/path/to/.hatfield

It prints aggregate counts, file classes, event-type counts, and SHA-256 ID
prefixes only. It continues to measure legacy prompt-cache diagnostics, which
are opt-in/default-off derived data rather than provider cache or replay state.
It never prints event payloads, prompts, tool output, or SQLite row values, and
it never writes below the audited root.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import sqlite3
import statistics
from collections import Counter, defaultdict
from pathlib import Path
from typing import Iterable


def fmt_size(value: int | float) -> str:
    for unit in ("B", "KiB", "MiB", "GiB"):
        if value < 1024:
            return f"{value:.1f} {unit}"
        value /= 1024
    return f"{value:.1f} TiB"


def percentile_95(values: list[int]) -> int:
    if not values:
        return 0
    if len(values) == 1:
        return values[0]
    return int(statistics.quantiles(values, n=20, method="inclusive")[18])


def anonymize(value: str) -> str:
    return hashlib.sha256(value.encode()).hexdigest()[:10]


def file_class(path: Path) -> str:
    if path.name in {
        "events.jsonl", "state.json", "sequence.cursor", "idempotency.jsonl",
        "prompt-cache.jsonl", "metadata.json", "handoff.md",
    }:
        return path.name
    if path.suffix in {".log", ".status", ".pid"}:
        return f"*{path.suffix}"
    return "other"


def files_under(root: Path) -> Iterable[tuple[Path, int]]:
    for directory, _, names in os.walk(root):
        for name in names:
            path = Path(directory, name)
            try:
                yield path, path.stat().st_size
            except OSError:
                continue


def event_summary(paths: Iterable[Path]) -> tuple[int, Counter[str], int]:
    records = 0
    types: Counter[str] = Counter()
    malformed = 0
    for path in paths:
        try:
            with path.open("rb") as handle:
                for line in handle:
                    if not line.strip():
                        continue
                    try:
                        payload = json.loads(line)
                    except (UnicodeDecodeError, json.JSONDecodeError):
                        malformed += 1
                        continue
                    if not isinstance(payload, dict):
                        malformed += 1
                        continue
                    records += 1
                    types[str(payload.get("type", payload.get("event_type", "<missing>")))] += 1
        except OSError:
            continue
    return records, types, malformed


def sqlite_summary(root: Path) -> None:
    database = root / "state.sqlite"
    if not database.is_file():
        print("SQLITE state.sqlite=absent")
        return
    try:
        connection = sqlite3.connect(database.as_uri() + "?mode=ro", uri=True)
        tables = [row[0] for row in connection.execute("SELECT name FROM sqlite_master WHERE type = 'table'")]
        counts: list[str] = []
        for table in sorted(tables):
            if table in {
                "hatfield_session", "background_process", "tool_question",
                "deferred_tool_completion", "deferred_subagent_batch", "deferred_subagent_child",
            }:
                quoted = table.replace('"', '""')
                count = connection.execute(f'SELECT COUNT(*) FROM "{quoted}"').fetchone()[0]
                counts.append(f"{table}={count}")
        print("SQLITE " + " ".join(counts))
    except (sqlite3.Error, OSError) as error:
        print(f"SQLITE unreadable={type(error).__name__}")
    finally:
        if "connection" in locals():
            connection.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="Read-only privacy-safe Hatfield storage audit")
    parser.add_argument("hatfield_root", type=Path, help="explicit .hatfield directory to audit")
    args = parser.parse_args()
    root = args.hatfield_root.expanduser().resolve()
    if root.name != ".hatfield" or not root.is_dir():
        parser.error("hatfield_root must be an existing directory named .hatfield")

    all_files = list(files_under(root))
    directories = sum(1 for _ in os.walk(root))
    print(f"ROOT {root}")
    print(f"TOTAL directories={directories} files={len(all_files)} bytes={fmt_size(sum(size for _, size in all_files))}")

    classes: dict[str, list[int]] = defaultdict(list)
    for path, size in all_files:
        classes[file_class(path)].append(size)
    print("PROMPT_CACHE_DIAGNOSTICS opt_in_default_off=true legacy_files_measured=true")
    print("FILE_CLASSES")
    for name in sorted(classes):
        values = classes[name]
        print(f"  {name}: count={len(values)} bytes={fmt_size(sum(values))} median={fmt_size(int(statistics.median(values)))} p95={fmt_size(percentile_95(values))} max={fmt_size(max(values))}")

    sessions = root / "sessions"
    if not sessions.is_dir():
        print("SESSIONS absent")
        sqlite_summary(root)
        return 0

    session_dirs = [path for path in sessions.iterdir() if path.is_dir()]
    numeric = [path for path in session_dirs if path.name.isdecimal()]
    idempotency_only = [
        path for path in session_dirs
        if not path.name.isdecimal() and [child.name for child in path.iterdir() if child.is_file()] == ["idempotency.jsonl"]
        and not any(child.is_dir() for child in path.iterdir())
    ]
    session_files = [(path, size) for path, size in files_under(sessions)]
    direct_parent_events = [path for path, _ in session_files if path.name == "events.jsonl" and path.parent.parent == sessions]
    child_events = [path for path, _ in session_files if path.name == "events.jsonl" and "artifacts" in path.parts and "agents" in path.parts]
    print(
        "SESSIONS "
        f"directories={len(session_dirs)} numeric_canonical_candidates={len(numeric)} "
        f"uuid_idempotency_only_candidates={len(idempotency_only)} bytes={fmt_size(sum(size for _, size in session_files))}"
    )
    print(f"EVENT_FILES parent={len(direct_parent_events)} bytes={fmt_size(sum(path.stat().st_size for path in direct_parent_events))} child={len(child_events)} bytes={fmt_size(sum(path.stat().st_size for path in child_events))}")

    for label, paths in (("PARENT_EVENTS", direct_parent_events), ("CHILD_EVENTS", child_events)):
        records, types, malformed = event_summary(paths)
        top = ", ".join(f"{name}={count}" for name, count in types.most_common(12)) or "none"
        print(f"{label} records={records} malformed_or_nonobject={malformed} types={top}")

    rows: list[tuple[Path, int, int]] = []
    for directory in session_dirs:
        children = list(files_under(directory))
        rows.append((directory, sum(size for _, size in children), len(children)))
    sizes = [size for _, size, _ in rows]
    if sizes:
        print(f"SESSION_SIZE bytes_min={fmt_size(min(sizes))} median={fmt_size(int(statistics.median(sizes)))} p95={fmt_size(percentile_95(sizes))} max={fmt_size(max(sizes))}")
        print("LARGEST_SESSION_DIRS")
        for directory, size, count in sorted(rows, key=lambda row: row[1], reverse=True)[:5]:
            print(f"  id_hash={anonymize(directory.name)} bytes={fmt_size(size)} files={count}")
    sqlite_summary(root)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
