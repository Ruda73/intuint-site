"""SQLite storage for sealed answers and outcomes.

SQLite needs no server, runs on shared hosting, and is one file to back up.
Sealed answers are never updated; outcomes are recorded alongside them.
"""
import sqlite3
from datetime import datetime, timezone

SCHEMA = """
CREATE TABLE IF NOT EXISTS answers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id       TEXT NOT NULL,
    job_title       TEXT,
    occupation      TEXT NOT NULL,
    question_id     TEXT NOT NULL,
    gut_answer      TEXT NOT NULL,
    confidence      INTEGER NOT NULL CHECK (confidence IN (50, 70, 90)),
    sealed_at       TEXT NOT NULL,
    outcome         TEXT,
    outcome_at      TEXT
);
CREATE INDEX IF NOT EXISTS idx_answers_member ON answers(member_id);
"""


def now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def connect(path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    conn.executescript(SCHEMA)
    return conn
