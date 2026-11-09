"""Offline fixtures: no live SQL Server access or credential file required."""
import importlib.util
from datetime import datetime
from pathlib import Path
import unittest

FILE = Path(__file__).with_name("restis-card-operation-reader.py")
spec = importlib.util.spec_from_file_location("restis_card_operation_reader", FILE)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def source_row(card, event, restaurant, bill, timestamp):
    return (card, event, restaurant, bill, datetime.fromisoformat(timestamp))


class ProximityCases(unittest.TestCase):
    def test_november_2_known_sql_operation_gap(self):
        result = module.detect_proximity([
            source_row("same-card", 1, 167, 961405, "2026-11-02T15:33:44"),
            source_row("same-card", 2, 62, 742989, "2026-11-02T15:44:49"),
        ])
        self.assertEqual(result["candidate_pairs"], 1)
        self.assertEqual(result["candidate_cards"], 1)
        self.assertEqual(int(result["cases"][0][0]), 665)

    def test_same_restaurant_is_not_cross_restaurant(self):
        self.assertEqual(module.detect_proximity([
            source_row("card", 1, 41, 11, "2026-11-02T10:00:00"),
            source_row("card", 2, 41, 12, "2026-11-02T10:00:01"),
        ])["candidate_pairs"], 0)

    def test_different_cards_are_not_linked(self):
        self.assertEqual(module.detect_proximity([
            source_row("card-A", 1, 41, 11, "2026-11-02T10:00:00"),
            source_row("card-B", 2, 42, 12, "2026-11-02T10:00:01"),
        ])["candidate_pairs"], 0)

    def test_outside_30_minutes_is_excluded(self):
        self.assertEqual(module.detect_proximity([
            source_row("card", 1, 41, 11, "2026-11-02T10:00:00"),
            source_row("card", 2, 42, 12, "2026-11-02T10:30:01"),
        ])["candidate_pairs"], 0)

    def test_closest_operation_selected_only_once_per_receipt_pair(self):
        result = module.detect_proximity([
            source_row("card", 1, 41, 11, "2026-11-02T10:00:00"),
            source_row("card", 2, 42, 12, "2026-11-02T10:20:00"),
            source_row("card", 3, 42, 12, "2026-11-02T10:21:00"),
            source_row("card", 4, 41, 11, "2026-11-02T10:22:00"),
        ])
        self.assertEqual(result["candidate_pairs"], 1)
        self.assertEqual(int(result["cases"][0][0]), 60)

    def test_duplicate_source_event_is_not_a_second_case(self):
        first = source_row("card", 1, 41, 11, "2026-11-02T10:00:00")
        result = module.detect_proximity([
            first, first,
            source_row("card", 2, 42, 12, "2026-11-02T10:10:00"),
        ])
        self.assertEqual(result["source_events"], 2)
        self.assertEqual(result["candidate_pairs"], 1)

    def test_conflicting_duplicate_event_rejected(self):
        with self.assertRaisesRegex(ValueError, "CONFLICTING_SOURCE_EVENT"):
            module.detect_proximity([
                source_row("card", 1, 41, 11, "2026-11-02T10:00:00"),
                source_row("card", 1, 42, 12, "2026-11-02T10:01:00"),
            ])


if __name__ == "__main__":
    unittest.main()
