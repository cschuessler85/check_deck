#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
@doc-file
@doc-id live_web.analyze_event_sqlite
@doc-kind cli-script
@doc-purpose Analysiert die SQLite-Datenbank eines 24h-Schwimmens und erzeugt umfassende Event-Statistiken.
@doc-entry CLI-Aufruf durch Entwickler oder Betrieb.
@doc-input SQLite-Datei mit Tabellen `schwimmer` und `actions`.
@doc-output Menschlich lesbare Zusammenfassung oder JSON-Report.
@doc-side-effects Liest die Datenbank nur lesend.
"""

from __future__ import annotations

import argparse
import json
import sqlite3
from collections import defaultdict
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple


POOL_LENGTH_METERS = 100
DEFAULT_DB_PATH = Path(__file__).resolve().parents[1] / "data.sqlite"
LAP_COMMANDS = {"ADD"}


@dataclass
class SwimmerStats:
    nummer: int
    vorname: str
    nachname: str
    ist_kind: bool
    gruppe: str
    action_laps: int
    stored_laps: int
    stored_distance_m: int

    @property
    def name(self) -> str:
        full = f"{self.vorname} {self.nachname}".strip()
        return full if full else f"Schwimmer {self.nummer}"

    @property
    def action_distance_m(self) -> int:
        return self.action_laps * POOL_LENGTH_METERS

    @property
    def action_distance_km(self) -> float:
        return self.action_distance_m / 1000.0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Analysiert `data.sqlite` fuer Gesamt-Event, Toplisten, Gruppenwertungen, "
            "Zeitverlauf und Validierung zwischen `actions` und `schwimmer`."
        )
    )
    parser.add_argument(
        "--db",
        default=str(DEFAULT_DB_PATH),
        help="Pfad zur SQLite-Datei. Standard: <projekt>/data.sqlite",
    )
    parser.add_argument(
        "--top",
        type=int,
        default=10,
        help="Anzahl Top-Eintraege fuer Ranglisten. Standard: 10",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Gibt den kompletten Report als JSON aus.",
    )
    return parser.parse_args()


def parse_parameter_array(raw_value: Any) -> List[Any]:
    if raw_value is None:
        return []
    if isinstance(raw_value, list):
        return list(raw_value)

    text = str(raw_value).strip()
    if not text:
        return []

    try:
        decoded = json.loads(text)
    except json.JSONDecodeError:
        decoded = None

    if isinstance(decoded, list):
        return decoded

    stripped = text.strip("[]() \t\r\n")
    if not stripped:
        return []

    values: List[Any] = []
    for part in stripped.split(","):
        item = part.strip().strip('"').strip("'")
        if item == "":
            continue
        try:
            values.append(int(float(item)))
        except ValueError:
            values.append(item)
    return values


def parse_int(value: Any, default: int = 0) -> int:
    if value is None:
        return default
    if isinstance(value, bool):
        return int(value)
    if isinstance(value, (int, float)):
        return int(value)

    text = str(value).strip().replace(",", ".")
    if not text:
        return default
    try:
        return int(float(text))
    except ValueError:
        return default


def parse_timestamp(value: Any) -> Optional[datetime]:
    if value is None:
        return None
    if isinstance(value, datetime):
        return value

    text = str(value).strip()
    if not text:
        return None

    candidates = [text.replace("Z", "+00:00")]
    if " " in text and "T" not in text:
        candidates.append(text.replace(" ", "T"))

    for candidate in candidates:
        try:
            return datetime.fromisoformat(candidate)
        except ValueError:
            continue

    known_formats = [
        "%Y-%m-%d %H:%M:%S",
        "%Y-%m-%d %H:%M:%S.%f",
        "%d.%m.%Y %H:%M:%S",
        "%d.%m.%Y %H:%M",
        "%Y-%m-%dT%H:%M:%S",
        "%Y-%m-%dT%H:%M:%S.%f",
    ]
    for fmt in known_formats:
        try:
            return datetime.strptime(text, fmt)
        except ValueError:
            continue
    return None


def fetch_rows(connection: sqlite3.Connection, query: str) -> List[sqlite3.Row]:
    cursor = connection.execute(query)
    return list(cursor.fetchall())


def load_swimmers(connection: sqlite3.Connection) -> Dict[int, Dict[str, Any]]:
    rows = fetch_rows(
        connection,
        """
        SELECT nummer, vorname, nachname, istKind, gruppe, bahnanzahl, strecke
        FROM schwimmer
        ORDER BY nummer ASC
        """,
    )
    swimmers: Dict[int, Dict[str, Any]] = {}
    for row in rows:
        nummer = parse_int(row["nummer"])
        if nummer <= 0:
            continue
        swimmers[nummer] = {
            "nummer": nummer,
            "vorname": str(row["vorname"] or "").strip(),
            "nachname": str(row["nachname"] or "").strip(),
            "ist_kind": parse_int(row["istKind"]) == 1,
            "gruppe": str(row["gruppe"] or "").strip(),
            "stored_laps": parse_int(row["bahnanzahl"]),
            "stored_distance_m": parse_int(row["strecke"]),
        }
    return swimmers


def load_action_aggregates(connection: sqlite3.Connection) -> Tuple[Dict[int, int], Dict[str, int], Dict[str, Any]]:
    rows = fetch_rows(
        connection,
        """
        SELECT id, zeitstempel, kommando, parameter
        FROM actions
        ORDER BY id ASC
        """,
    )
    laps_by_swimmer: Dict[int, int] = defaultdict(int)
    laps_by_hour: Dict[str, int] = defaultdict(int)
    debug = {
        "total_actions": len(rows),
        "lap_actions": 0,
        "skipped_actions": 0,
        "unmapped_actions": 0,
        "negative_delta_actions": 0,
    }

    for row in rows:
        command = str(row["kommando"] or "").strip().upper()
        if command not in LAP_COMMANDS:
            continue

        params = parse_parameter_array(row["parameter"])
        if len(params) < 2:
            debug["skipped_actions"] += 1
            continue

        swimmer_nummer = parse_int(params[0])
        lap_delta = parse_int(params[1])
        if swimmer_nummer <= 0:
            debug["unmapped_actions"] += 1
            continue

        laps_by_swimmer[swimmer_nummer] += lap_delta
        debug["lap_actions"] += 1
        if lap_delta < 0:
            debug["negative_delta_actions"] += 1

        ts = parse_timestamp(row["zeitstempel"])
        if ts is not None:
            hour_bucket = ts.strftime("%Y-%m-%d %H:00")
            laps_by_hour[hour_bucket] += lap_delta

    return dict(laps_by_swimmer), dict(sorted(laps_by_hour.items())), debug


def build_swimmer_stats(
    swimmers: Dict[int, Dict[str, Any]],
    laps_by_swimmer: Dict[int, int],
) -> List[SwimmerStats]:
    nummern = sorted(set(swimmers.keys()) | set(laps_by_swimmer.keys()))
    results: List[SwimmerStats] = []
    for nummer in nummern:
        row = swimmers.get(
            nummer,
            {
                "nummer": nummer,
                "vorname": "",
                "nachname": "",
                "ist_kind": False,
                "gruppe": "",
                "stored_laps": 0,
                "stored_distance_m": 0,
            },
        )
        results.append(
            SwimmerStats(
                nummer=nummer,
                vorname=row["vorname"],
                nachname=row["nachname"],
                ist_kind=bool(row["ist_kind"]),
                gruppe=row["gruppe"],
                action_laps=laps_by_swimmer.get(nummer, 0),
                stored_laps=int(row["stored_laps"]),
                stored_distance_m=int(row["stored_distance_m"]),
            )
        )
    return results


def sort_swimmers(swimmers: Iterable[SwimmerStats]) -> List[SwimmerStats]:
    return sorted(
        swimmers,
        key=lambda item: (-item.action_laps, item.name.lower(), item.nummer),
    )


def build_top_list(swimmers: Sequence[SwimmerStats], limit: int) -> List[Dict[str, Any]]:
    return [
        {
            "rank": index + 1,
            "nummer": swimmer.nummer,
            "name": swimmer.name,
            "gruppe": swimmer.gruppe,
            "ist_kind": swimmer.ist_kind,
            "laps": swimmer.action_laps,
            "distance_m": swimmer.action_distance_m,
            "distance_km": round(swimmer.action_distance_km, 2),
        }
        for index, swimmer in enumerate(swimmers[:limit])
    ]


def build_group_stats(swimmers: Sequence[SwimmerStats]) -> Dict[str, List[Dict[str, Any]]]:
    groups: Dict[str, Dict[str, Any]] = {}
    for swimmer in swimmers:
        group_name = swimmer.gruppe.strip()
        if not group_name:
            continue
        entry = groups.setdefault(
            group_name,
            {
                "gruppe": group_name,
                "members": 0,
                "total_laps": 0,
            },
        )
        entry["members"] += 1
        entry["total_laps"] += swimmer.action_laps

    group_rows: List[Dict[str, Any]] = []
    for entry in groups.values():
        members = int(entry["members"])
        total_laps = int(entry["total_laps"])
        group_rows.append(
            {
                "gruppe": entry["gruppe"],
                "members": members,
                "total_laps": total_laps,
                "total_distance_m": total_laps * POOL_LENGTH_METERS,
                "total_distance_km": round((total_laps * POOL_LENGTH_METERS) / 1000.0, 2),
                "avg_laps_per_member": round(total_laps / members, 2) if members else 0.0,
                "avg_distance_km_per_member": round(((total_laps * POOL_LENGTH_METERS) / 1000.0) / members, 2)
                if members
                else 0.0,
            }
        )

    by_total = sorted(group_rows, key=lambda row: (-int(row["total_laps"]), str(row["gruppe"]).lower()))
    for index, row in enumerate(by_total):
        row["rank_total"] = index + 1

    by_average = sorted(
        group_rows,
        key=lambda row: (-float(row["avg_laps_per_member"]), -int(row["total_laps"]), str(row["gruppe"]).lower()),
    )
    for index, row in enumerate(by_average):
        row["rank_average"] = index + 1

    return {
        "by_total_distance": by_total,
        "by_average_distance_per_member": by_average,
    }


def build_validation(swimmers: Sequence[SwimmerStats], limit: int = 15) -> Dict[str, Any]:
    mismatches_laps: List[Dict[str, Any]] = []
    mismatches_distance: List[Dict[str, Any]] = []

    for swimmer in swimmers:
        lap_diff = swimmer.action_laps - swimmer.stored_laps
        if lap_diff != 0:
            mismatches_laps.append(
                {
                    "nummer": swimmer.nummer,
                    "name": swimmer.name,
                    "action_laps": swimmer.action_laps,
                    "stored_laps": swimmer.stored_laps,
                    "lap_diff": lap_diff,
                }
            )

        distance_diff = swimmer.action_distance_m - swimmer.stored_distance_m
        if distance_diff != 0:
            mismatches_distance.append(
                {
                    "nummer": swimmer.nummer,
                    "name": swimmer.name,
                    "action_distance_m": swimmer.action_distance_m,
                    "stored_distance_m": swimmer.stored_distance_m,
                    "distance_diff_m": distance_diff,
                }
            )

    mismatches_laps.sort(key=lambda row: (-abs(int(row["lap_diff"])), str(row["name"]).lower(), int(row["nummer"])))
    mismatches_distance.sort(
        key=lambda row: (-abs(int(row["distance_diff_m"])), str(row["name"]).lower(), int(row["nummer"]))
    )

    total_action_laps = sum(swimmer.action_laps for swimmer in swimmers)
    total_stored_laps = sum(swimmer.stored_laps for swimmer in swimmers)
    total_action_distance_m = sum(swimmer.action_distance_m for swimmer in swimmers)
    total_stored_distance_m = sum(swimmer.stored_distance_m for swimmer in swimmers)

    return {
        "summary": {
            "total_action_laps": total_action_laps,
            "total_stored_laps": total_stored_laps,
            "lap_diff_total": total_action_laps - total_stored_laps,
            "total_action_distance_m": total_action_distance_m,
            "total_stored_distance_m": total_stored_distance_m,
            "distance_diff_total_m": total_action_distance_m - total_stored_distance_m,
            "lap_mismatch_count": len(mismatches_laps),
            "distance_mismatch_count": len(mismatches_distance),
        },
        "top_lap_mismatches": mismatches_laps[:limit],
        "top_distance_mismatches": mismatches_distance[:limit],
    }


def build_report(connection: sqlite3.Connection, top_limit: int) -> Dict[str, Any]:
    swimmers = load_swimmers(connection)
    laps_by_swimmer, laps_by_hour, action_debug = load_action_aggregates(connection)
    swimmer_stats = build_swimmer_stats(swimmers, laps_by_swimmer)

    all_ranked = sort_swimmers(swimmer_stats)
    adults_ranked = sort_swimmers(swimmer for swimmer in swimmer_stats if not swimmer.ist_kind)
    kids_ranked = sort_swimmers(swimmer for swimmer in swimmer_stats if swimmer.ist_kind)
    group_stats = build_group_stats(swimmer_stats)
    validation = build_validation(swimmer_stats)

    total_laps = sum(swimmer.action_laps for swimmer in swimmer_stats)
    total_distance_m = total_laps * POOL_LENGTH_METERS

    return {
        "meta": {
            "database": str(Path(connection.execute("PRAGMA database_list").fetchone()[2]).resolve()),
            "pool_length_m": POOL_LENGTH_METERS,
            "generated_at": datetime.now().isoformat(timespec="seconds"),
            "top_limit": top_limit,
        },
        "event_totals": {
            "participants_total": len(swimmer_stats),
            "participants_children": sum(1 for swimmer in swimmer_stats if swimmer.ist_kind),
            "participants_adults": sum(1 for swimmer in swimmer_stats if not swimmer.ist_kind),
            "laps_total": total_laps,
            "distance_m_total": total_distance_m,
            "distance_km_total": round(total_distance_m / 1000.0, 2),
        },
        "top_swimmers": {
            "overall": build_top_list(all_ranked, top_limit),
            "adults": build_top_list(adults_ranked, top_limit),
            "children": build_top_list(kids_ranked, top_limit),
        },
        "groups": group_stats,
        "timeline": [
            {
                "hour": hour,
                "laps": laps,
                "distance_m": laps * POOL_LENGTH_METERS,
                "distance_km": round((laps * POOL_LENGTH_METERS) / 1000.0, 2),
            }
            for hour, laps in laps_by_hour.items()
        ],
        "validation": validation,
        "debug": action_debug,
    }


def print_table(title: str, rows: Sequence[Dict[str, Any]], columns: Sequence[Tuple[str, str]]) -> None:
    print(f"\n{title}")
    print("-" * len(title))
    if not rows:
        print("Keine Eintraege vorhanden.")
        return

    widths = []
    for key, label in columns:
        cell_width = max(len(label), *(len(str(row.get(key, ""))) for row in rows))
        widths.append(cell_width)

    header = " | ".join(label.ljust(widths[index]) for index, (_, label) in enumerate(columns))
    separator = "-+-".join("-" * width for width in widths)
    print(header)
    print(separator)
    for row in rows:
        print(" | ".join(str(row.get(key, "")).ljust(widths[index]) for index, (key, _) in enumerate(columns)))


def print_report(report: Dict[str, Any]) -> None:
    meta = report["meta"]
    totals = report["event_totals"]

    print("24h-Schwimmen SQLite-Analyse")
    print("============================")
    print(f"Datenbank      : {meta['database']}")
    print(f"Generiert am   : {meta['generated_at']}")
    print(f"Beckenlaenge   : {meta['pool_length_m']} m pro Bahn")
    print(f"Teilnehmer     : {totals['participants_total']} gesamt ({totals['participants_children']} Kinder, {totals['participants_adults']} Erwachsene)")
    print(f"Gesamtbahnen   : {totals['laps_total']}")
    print(f"Gesamtdistanz  : {totals['distance_km_total']:.2f} km")

    print_table(
        "Top 10 Gesamt",
        report["top_swimmers"]["overall"],
        [("rank", "#"), ("nummer", "Nr"), ("name", "Name"), ("gruppe", "Team"), ("laps", "Bahnen"), ("distance_km", "km")],
    )
    print_table(
        "Top 10 Erwachsene",
        report["top_swimmers"]["adults"],
        [("rank", "#"), ("nummer", "Nr"), ("name", "Name"), ("gruppe", "Team"), ("laps", "Bahnen"), ("distance_km", "km")],
    )
    print_table(
        "Top 10 Kinder",
        report["top_swimmers"]["children"],
        [("rank", "#"), ("nummer", "Nr"), ("name", "Name"), ("gruppe", "Team"), ("laps", "Bahnen"), ("distance_km", "km")],
    )
    print_table(
        "Gruppenwertung nach Gesamtdistanz",
        report["groups"]["by_total_distance"][:10],
        [("rank_total", "#"), ("gruppe", "Team"), ("members", "Mitglieder"), ("total_laps", "Bahnen"), ("total_distance_km", "km")],
    )
    print_table(
        "Gruppenwertung nach Durchschnitt pro Kopf",
        report["groups"]["by_average_distance_per_member"][:10],
        [("rank_average", "#"), ("gruppe", "Team"), ("members", "Mitglieder"), ("avg_laps_per_member", "Ø Bahnen"), ("avg_distance_km_per_member", "Ø km")],
    )
    print_table(
        "Zeitlicher Verlauf pro Stunde",
        report["timeline"],
        [("hour", "Stunde"), ("laps", "Bahnen"), ("distance_km", "km")],
    )

    validation = report["validation"]["summary"]
    print("\nValidierung")
    print("-----------")
    print(f"Actions Bahnen gesamt : {validation['total_action_laps']}")
    print(f"Feld bahnanzahl gesamt: {validation['total_stored_laps']}")
    print(f"Differenz Bahnen      : {validation['lap_diff_total']}")
    print(f"Actions Distanz gesamt: {validation['total_action_distance_m']} m")
    print(f"Feld strecke gesamt   : {validation['total_stored_distance_m']} m")
    print(f"Differenz Distanz     : {validation['distance_diff_total_m']} m")
    print(f"Abweichungen Bahnen   : {validation['lap_mismatch_count']} Schwimmer")
    print(f"Abweichungen Strecke  : {validation['distance_mismatch_count']} Schwimmer")

    print_table(
        "Groesste Abweichungen (Bahnen)",
        report["validation"]["top_lap_mismatches"][:10],
        [("nummer", "Nr"), ("name", "Name"), ("action_laps", "Actions"), ("stored_laps", "bahnanzahl"), ("lap_diff", "Diff")],
    )


def main() -> int:
    args = parse_args()
    db_path = Path(args.db).expanduser().resolve()
    if not db_path.is_file():
        raise FileNotFoundError(f"SQLite-Datei nicht gefunden: {db_path}")

    connection = sqlite3.connect(str(db_path))
    connection.row_factory = sqlite3.Row
    try:
        report = build_report(connection, max(1, args.top))
    finally:
        connection.close()

    if args.json:
        print(json.dumps(report, ensure_ascii=False, indent=2))
    else:
        print_report(report)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
