#!/usr/bin/env python3
"""Read-only RESTISBONUS cross-restaurant operation proximity audit.

Uses the site's existing event_worker.connect_source() but never runs the
bonus worker. Does not change Bitrix, RestIS, SQL permissions or risk scores.
No card numbers, waiter names or connection secrets are printed.
"""
from __future__ import annotations

import argparse
import contextlib
from datetime import datetime, timedelta
import io
import os
import sys

WORKER_DIRECTORY = "/root/ev-bonus-sync-runtime/event_sync"
FREETDS_CONFIG = "/root/ev-bonus-secrets/freetds.conf"
EXPECTED_DATABASE = "RESTISBONUS"
EXPECTED_LOGIN = "ev_bonus_sync_ro"
MAX_SOURCE_ROWS = 200000
MAX_GAP_SECONDS = 1800


def detect_proximity(rows, max_gap_seconds=MAX_GAP_SECONDS):
    """Rows: (CardNumber, ID, ID_OBJECT, ID_BILL, DateWrite).

    Return one closest operation pair for each same-card, cross-restaurant
    physical receipt pair. All identity values remain in process memory.
    """
    if not isinstance(max_gap_seconds, int) or not 1 <= max_gap_seconds <= 3600:
        raise ValueError("INVALID_GAP")

    by_card = {}
    seen = {}
    for card_number, event_id, restaurant_id, bill_id, happened_at in rows:
        card = str(card_number or "").strip()
        if (not card or event_id is None or restaurant_id is None
                or bill_id is None or not isinstance(happened_at, datetime)):
            continue
        event_id = int(event_id)
        restaurant_id = int(restaurant_id)
        bill_id = int(bill_id)
        if event_id <= 0 or restaurant_id <= 0 or bill_id <= 0:
            continue
        row = (restaurant_id, bill_id, happened_at)
        identity = (card, event_id)
        previous = seen.get(identity)
        if previous is not None:
            if previous != row:
                raise ValueError("CONFLICTING_SOURCE_EVENT")
            continue
        seen[identity] = row
        by_card.setdefault(card, []).append((event_id,) + row)

    nearest = {}
    for card, operations in by_card.items():
        operations.sort(key=lambda record: (record[3], record[0]))
        for index, first in enumerate(operations):
            for second in operations[index + 1:]:
                gap_seconds = (second[3] - first[3]).total_seconds()
                if gap_seconds > max_gap_seconds:
                    break
                if first[1] == second[1]:
                    continue

                bill_a = (first[1], first[2])
                bill_b = (second[1], second[2])
                pair_key = (card, tuple(sorted((bill_a, bill_b))))
                case = (gap_seconds, first[1], first[2], first[3],
                        second[1], second[2], second[3])
                previous_case = nearest.get(pair_key)
                if previous_case is None or case < previous_case:
                    nearest[pair_key] = case

    ordered = sorted(nearest.items(), key=lambda item: (item[1][0], item[1][3]))
    return {
        "source_events": len(seen),
        "cards_in_window": len(by_card),
        "candidate_pairs": len(ordered),
        "candidate_cards": len({key[0] for key, _ in ordered}),
        "cases": [case for _, case in ordered],
    }


def connect_readonly():
    os.environ["FREETDSCONF"] = FREETDS_CONFIG
    sys.path.insert(0, WORKER_DIRECTORY)
    with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
        import event_worker
        return event_worker.connect_source()


def run(days):
    conn = None
    try:
        print("=== 1. PRODUCTION / CONNECTION GUARDS ===")
        if os.geteuid() != 0 or os.uname().nodename.split(".")[0] != "evrasia":
            raise RuntimeError("WRONG_HOST_OR_USER")
        if not os.path.isfile(os.path.join(WORKER_DIRECTORY, "event_worker.py")):
            raise RuntimeError("WORKER_MODULE_MISSING")
        if not os.path.isfile(FREETDS_CONFIG):
            raise RuntimeError("FREETDS_CONFIG_MISSING")
        conn = connect_readonly()
        cur = conn.cursor()
        cur.execute("SET LOCK_TIMEOUT 3000")
        cur.execute("SELECT DB_NAME(), ORIGINAL_LOGIN(), CONVERT(datetime, CONVERT(date, GETDATE()))")
        db, login, midnight = cur.fetchone()
        if db != EXPECTED_DATABASE or login != EXPECTED_LOGIN:
            raise RuntimeError("UNEXPECTED_SQL_IDENTITY")
        print("PASS: EXACT_PRODUCTION")
        print("PASS: READ_ONLY_SQL_LOGIN")

        print()
        print("=== 2. BOUNDED OPERATION READ ===")
        from_time = midnight - timedelta(days=days)
        sql = """
            SELECT TOP (200001) CardNumber, ID, ID_OBJECT, ID_BILL, DateWrite
            FROM dbo.RB_CARD_LOG
            WHERE DateWrite >= %s AND DateWrite < %s
              AND Bill_Sum > 0 AND (REJECT = 0 OR REJECT IS NULL)
              AND CardNumber IS NOT NULL
              AND ID_OBJECT IS NOT NULL AND ID_BILL IS NOT NULL
            OPTION (MAXDOP 1)
        """
        cur.execute(sql, (from_time, midnight))
        records = cur.fetchall()
        if len(records) > MAX_SOURCE_ROWS:
            raise RuntimeError("SOURCE_ROW_LIMIT_EXCEEDED")
        print("WINDOW_DAYS=" + str(days))
        print("OPERATION_ROWS=" + str(len(records)))
        print("PASS: READ_ONLY_SOURCE_QUERY")

        print()
        print("=== 3. DIFFERENT-RESTAURANT PROXIMITY ===")
        result = detect_proximity(records)
        print("UNIQUE_POSITIVE_SOURCE_EVENTS=" + str(result["source_events"]))
        print("SOURCE_CARDS=" + str(result["cards_in_window"]))
        print("CANDIDATE_CARDS=" + str(result["candidate_cards"]))
        print("CANDIDATE_RECEIPT_PAIRS=" + str(result["candidate_pairs"]))
        print("MAX_GAP_SECONDS=" + str(MAX_GAP_SECONDS))
        print("EXAMPLE_COUNT=" + str(min(20, len(result["cases"]))))
        for position, case in enumerate(result["cases"][:20], start=1):
            gap, r1, b1, t1, r2, b2, t2 = case
            print(
                "CASE_" + str(position)
                + " RESTAURANT_1=" + str(r1)
                + " BILL_1=" + str(b1)
                + " RESTAURANT_2=" + str(r2)
                + " BILL_2=" + str(b2)
                + " OPERATION_1=" + t1.strftime("%Y-%m-%dT%H:%M:%S")
                + " OPERATION_2=" + t2.strftime("%Y-%m-%dT%H:%M:%S")
                + " GAP_SECONDS=" + str(int(gap))
            )

        print()
        print("=== 4. EVIDENCE QUALITY ===")
        print("EVIDENCE=POSITIVE_BONUS_OPERATIONS")
        print("RECEIPT_CLOSE_CONFIRMED=NO")
        print("CUSTOMER_PRESENCE_CONFIRMED=NO")
        print("SCORE_CHANGE=NO")
        print("AUTOBLOCK=NO")
        print("CARD_NUMBERS_PRINTED=NO")
        print("SECRET_VALUES_PRINTED=NO")
    except Exception as exc:
        print("FAIL: " + (
            str(exc) if isinstance(exc, (RuntimeError, ValueError))
            else type(exc).__name__
        ))
        return 1
    finally:
        if conn is not None:
            try:
                conn.rollback()
            except Exception:
                pass
            try:
                conn.close()
            except Exception:
                pass
    return 0


def main():
    parser = argparse.ArgumentParser(description="Read-only card operation proximity check")
    parser.add_argument("--days", type=int, default=7)
    args = parser.parse_args()
    if not 1 <= args.days <= 7:
        parser.error("--days must be 1..7")
    return run(args.days)


if __name__ == "__main__":
    sys.exit(main())
