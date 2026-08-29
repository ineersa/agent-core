# Run operational state

```mermaid
flowchart LR
    Controller[Controller]:::controller
    Control{{run_control consumer}}:::control
    Cache[(ActiveRunContext cache)]:::memory
    Events[/events.jsonl/]:::events
    State[(run_operational_state)]:::db
    Tool[(run_operational_tool_call)]:::db
    Human[(run_operational_human_input)]:::db
    Llm{{LLM worker}}:::worker
    Execution{{tool and shell workers}}:::worker

    Controller --> Control
    Control --> Cache
    Cache -->|cache miss replay| Events
    Control -->|event first| Events
    Control -->|ordinary transactional upsert| State
    Control --> Tool
    Control --> Human
    Control --> Llm
    Control --> Execution
    Llm --> Control
    Execution --> Control
    Llm -. narrow cancellation read .-> State
    Execution -. narrow cancellation read .-> State

    classDef controller fill:#4b2e83,color:#fff,stroke:#c4a7ff
    classDef control fill:#0b7285,color:#fff,stroke:#66d9e8
    classDef memory fill:#7a1f3d,color:#fff,stroke:#ff8a9b
    classDef events fill:#53610f,color:#fff,stroke:#d8f36a
    classDef db fill:#12613a,color:#fff,stroke:#69db9b
    classDef worker fill:#9c5c00,color:#fff,stroke:#ffd166
```

```mermaid
sequenceDiagram
    participant RC as run_control
    participant E as events.jsonl
    participant P as operational projection
    participant M as memory cache
    RC->>E: append canonical event
    RC->>P: replace bounded rows
    RC->>M: remember state
    Note over RC,M: projection failure invalidates memory
    Note over E,P: startup cleanup then canonical replay repairs rows
```

```mermaid
flowchart TD
    Parent([top-level run owner]):::parent
    Child([child run]):::child
    Parent -->|owner_session_id| State[(state row)]:::db
    Child -->|parent_run_id + owner_session_id| ChildState[(child state row)]:::db
    ChildState --> ChildTool[(child tool rows)]:::db
    ChildState --> ChildHuman[(child human rows)]:::db
    Lock{{controller session-owner lock}}:::control --> Cleanup[owner-scoped startup cleanup]:::control
    Cleanup --> State
    Cleanup --> ChildState
    classDef parent fill:#4b2e83,color:#fff,stroke:#c4a7ff
    classDef child fill:#9c5c00,color:#fff,stroke:#ffd166
    classDef db fill:#12613a,color:#fff,stroke:#69db9b
    classDef control fill:#0b7285,color:#fff,stroke:#66d9e8
```

```sql
CREATE TABLE run_operational_state (
    run_id VARCHAR(255) NOT NULL PRIMARY KEY,
    parent_run_id VARCHAR(255) DEFAULT NULL,
    owner_session_id VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL,
    turn_no INTEGER NOT NULL,
    active_step_id VARCHAR(255) DEFAULT NULL,
    operation_turn_no INTEGER DEFAULT NULL,
    operation_step_id VARCHAR(255) DEFAULT NULL,
    operation_attempt INTEGER DEFAULT NULL,
    operation_key VARCHAR(255) DEFAULT NULL,
    last_applied_advance_key VARCHAR(255) DEFAULT NULL,
    last_applied_compaction_key VARCHAR(255) DEFAULT NULL,
    retryable_failure BOOLEAN NOT NULL,
    retry_attempts INTEGER NOT NULL,
    last_event_sequence INTEGER NOT NULL,
    transition_version INTEGER NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);
CREATE INDEX idx_run_operational_state_owner ON run_operational_state (owner_session_id);
CREATE INDEX idx_run_operational_state_status ON run_operational_state (status);

CREATE TABLE run_operational_tool_call (
    run_id VARCHAR(255) NOT NULL,
    batch_id VARCHAR(255) NOT NULL,
    tool_call_id VARCHAR(255) NOT NULL,
    order_index INTEGER NOT NULL,
    status VARCHAR(32) NOT NULL,
    attempt INTEGER NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (run_id, batch_id, tool_call_id),
    FOREIGN KEY (run_id) REFERENCES run_operational_state (run_id) ON DELETE CASCADE
);
CREATE INDEX idx_run_operational_tool_current ON run_operational_tool_call (run_id, batch_id, status, order_index);

CREATE TABLE run_operational_human_input (
    run_id VARCHAR(255) NOT NULL,
    question_id VARCHAR(255) NOT NULL,
    order_index INTEGER NOT NULL,
    continuation_kind VARCHAR(32) NOT NULL,
    tool_call_id VARCHAR(255) DEFAULT NULL,
    status VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (run_id, question_id),
    FOREIGN KEY (run_id) REFERENCES run_operational_state (run_id) ON DELETE CASCADE
);
CREATE INDEX idx_run_operational_human_current ON run_operational_human_input (run_id, status, order_index);
```
