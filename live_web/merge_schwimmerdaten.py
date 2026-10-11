#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
@doc-file
@doc-id live_web.merge_schwimmerdaten
@doc-kind cli-script
@doc-purpose Fuehrt CSV-Stammdaten und JSON-Eventlogs zu einer aktualisierten Ergebnis-CSV zusammen.
@doc-entry CLI-Aufruf durch Entwickler oder Betrieb.
@doc-input CSV-Datei, JSON-Backup und optionale Zeitfensterdatei.
@doc-output Aktualisierte CSV-Datei und Statusausgaben im Terminal.
@doc-side-effects Liest Eingabedateien und schreibt eine neue Ausgabedatei.
"""

import argparse
import csv
import json
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, Iterable, Iterator, List, Optional, Sequence, Tuple


DEFAULT_INPUT_CSV_NAMES = ("schwimmerdaten.csv",)
DEFAULT_INPUT_JSON_NAMES = ("view_backup.json", "backup.json")

EXPECTED_COLUMNS = [
    "nummer",
    "vorname",
    "nachname",
    "gruppe",
    "bahnanzahl",
    "Tag1",
    "Geisterstunde",
    "Gute Nacht",
    "Frühaufsteher",
    "Tag2",
]

NUMERIC_COLUMNS = {"bahnanzahl", "Tag1", "Geisterstunde", "Gute Nacht", "Frühaufsteher", "Tag2"}
EVENT_LIST_KEYS = ("protokoll", "history", "logs", "event_logs", "events", "actions")
COMMAND_KEYS = ("kommando", "command", "aktion")
TIMESTAMP_KEYS = ("timestamp", "zeitstempel", "created_at", "createdAt", "time", "date")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Liest Schwimmerdaten aus CSV und ADD-Events aus einer JSON-Backup-Datei "
            "ein und schreibt das kombinierte Ergebnis in eine neue CSV-Datei."
        )
    )
    parser.add_argument("--csv", help="Pfad zur Eingabe-CSV. Wenn leer, wird im aktuellen Ordner gesucht.")
    parser.add_argument("--json", dest="json_path", help="Pfad zur JSON-Backup-Datei. Wenn leer, wird im aktuellen Ordner gesucht.")
    parser.add_argument(
        "--output",
        help=(
            "Pfad zur Ausgabedatei. Wenn leer, wird neben der CSV automatisch "
            "<csv-name>_aktualisiert.csv erzeugt."
        ),
    )
    parser.add_argument(
        "--skip-missing-swimmers",
        action="store_true",
        help="Unbekannte Schwimmer aus dem JSON ueberspringen statt neue Zeilen anzulegen.",
    )
    parser.add_argument(
        "--timeframes",
        help="Pfad zu einer JSON-Datei mit Zeitfenstern fuer die Kategorisierung (optional).",
    )
    parser.add_argument(
        "--reset-calculation-columns",
        action="store_true",
        help="Setzt die Berechnungsspalten vor dem Merge auf 0 statt auf bestehende Werte aufzuaddieren.",
    )
    return parser.parse_args()


def find_existing_file(candidate_names: Sequence[str]) -> Optional[Path]:
    for name in candidate_names:
        candidate = Path(name)
        if candidate.is_file():
            return candidate.resolve()
    return None


def resolve_input_path(explicit_path: Optional[str], fallback_names: Sequence[str], label: str) -> Path:
    if explicit_path:
        path = Path(explicit_path).expanduser()
        if not path.is_file():
            raise FileNotFoundError(f"{label} nicht gefunden: {path}")
        return path.resolve()

    discovered = find_existing_file(fallback_names)
    if discovered is not None:
        return discovered

    tried = ", ".join(fallback_names)
    raise FileNotFoundError(
        f"{label} wurde nicht angegeben und keine Standarddatei gefunden. Geprueft: {tried}"
    )


def build_output_path(csv_path: Path, explicit_output: Optional[str]) -> Path:
    if explicit_output:
        return Path(explicit_output).expanduser().resolve()

    return csv_path.with_name(f"{csv_path.stem}_aktualisiert.csv")


def detect_csv_dialect(file_path: Path) -> csv.Dialect:
    with file_path.open("r", encoding="utf-8-sig", newline="") as handle:
        sample = handle.read(4096)

    if not sample.strip():
        raise ValueError(f"CSV-Datei ist leer: {file_path}")

    try:
        return csv.Sniffer().sniff(sample, delimiters=";,")
    except csv.Error:
        delimiter = ";" if sample.count(";") >= sample.count(",") else ","
        dialect = csv.excel()
        dialect.delimiter = delimiter
        return dialect


def normalise_number(value: Any) -> str:
    if value is None:
        return ""

    text = str(value).strip()
    if not text:
        return ""

    try:
        return str(int(float(text)))
    except (TypeError, ValueError):
        return text


def parse_int(value: Any, default: int = 0) -> int:
    if value is None:
        return default
    if isinstance(value, bool):
        return int(value)

    text = str(value).strip().replace(",", ".")
    if not text:
        return default

    try:
        return int(float(text))
    except ValueError:
        return default


def parse_timestamp(timestamp_value: Any) -> datetime:
    if isinstance(timestamp_value, datetime):
        return timestamp_value

    if timestamp_value is None:
        raise ValueError("Timestamp fehlt")

    text = str(timestamp_value).strip()
    if not text:
        raise ValueError("Timestamp ist leer")

    iso_candidates = [text.replace("Z", "+00:00")]
    if " " in text and "T" not in text:
        iso_candidates.append(text.replace(" ", "T"))

    for candidate in iso_candidates:
        try:
            return datetime.fromisoformat(candidate)
        except ValueError:
            pass

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
            pass

    raise ValueError(f"Timestamp konnte nicht geparst werden: {timestamp_value}")


def bucket_for_hour(hour: int) -> str:
    if hour == 0:
        return "Geisterstunde"
    if hour in {1, 2, 3, 4}:
        return "Gute Nacht"
    if hour == 5:
        return "Frühaufsteher"
    return "Tag2"


def parse_bool(value: Any, default: bool = False) -> bool:
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return int(value) != 0
    text = str(value).strip().lower()
    if text == "":
        return default
    return text in {"1", "true", "yes", "ja", "on"}


def load_timeframes(file_path: Path) -> List[dict]:
    with file_path.open("r", encoding="utf-8-sig") as handle:
        payload = json.load(handle)

    items: Any = payload
    if isinstance(payload, dict):
        for key in ("timeframes", "frames", "windows"):
            if isinstance(payload.get(key), list):
                items = payload.get(key)
                break

    if not isinstance(items, list):
        raise ValueError("Zeitfenster-JSON muss eine Liste oder ein Objekt mit Liste sein.")

    frames: List[dict] = []
    for raw in items:
        if not isinstance(raw, dict):
            continue
        name = str(raw.get("name") or raw.get("label") or "").strip()
        if not name:
            continue
        active = parse_bool(raw.get("active"), True)
        if not active:
            continue

        start_value = raw.get("start_at") or raw.get("start") or raw.get("from")
        end_value = raw.get("end_at") or raw.get("end") or raw.get("to")
        if start_value is None or end_value is None:
            continue
        start_at = parse_timestamp(start_value)
        end_at = parse_timestamp(end_value)
        if start_at >= end_at:
            continue
        sort_order = parse_int(raw.get("sort_order"), 0)

        frames.append(
            {
                "name": name,
                "start_at": start_at,
                "end_at": end_at,
                "sort_order": sort_order,
            }
        )

    frames.sort(key=lambda f: (int(f.get("sort_order", 0)), f["start_at"], f["end_at"], f["name"]))
    return frames


def bucket_for_timestamp(ts: datetime, timeframes: Optional[List[dict]]) -> str:
    if timeframes:
        for frame in timeframes:
            if frame["start_at"] <= ts < frame["end_at"]:
                return str(frame["name"])
    return bucket_for_hour(ts.hour)


def load_csv_rows(file_path: Path) -> Tuple[List[Dict[str, str]], List[str], str]:
    print(f"[1/4] Lade CSV-Datei: {file_path}")
    dialect = detect_csv_dialect(file_path)

    with file_path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle, dialect=dialect)
        if not reader.fieldnames:
            raise ValueError("CSV-Datei enthaelt keine Kopfzeile.")
        headers = [header.strip() if header else "" for header in reader.fieldnames]
        rows = [{(key.strip() if key else ""): (value or "").strip() for key, value in row.items()} for row in reader]

    print(f"    -> {len(rows)} CSV-Zeilen geladen")
    print(f"    -> Trennzeichen erkannt: '{dialect.delimiter}'")
    return rows, headers, dialect.delimiter


def ensure_row_shape(row: Dict[str, str], headers: Iterable[str], numeric_columns: Sequence[str]) -> Dict[str, str]:
    cleaned: Dict[str, str] = {}
    numeric_set = set(numeric_columns)
    for header in headers:
        value = row.get(header, "")
        if header in numeric_set:
            cleaned[header] = str(parse_int(value, 0))
        else:
            cleaned[header] = "" if value is None else str(value).strip()
    return cleaned


def reset_calculation_columns(rows: List[Dict[str, str]], numeric_columns: Sequence[str]) -> None:
    print("    -> Setze Berechnungsspalten vor dem Merge auf 0")
    numeric_set = set(numeric_columns)
    for row in rows:
        for header in list(row.keys()):
            if header in numeric_set:
                row[header] = "0"


def looks_like_event_entry(entry: Any) -> bool:
    if not isinstance(entry, dict):
        return False

    has_command = any(key in entry for key in COMMAND_KEYS)
    has_parameter = "parameter" in entry or "params" in entry
    has_timestamp = any(key in entry for key in TIMESTAMP_KEYS)
    return has_command and (has_parameter or has_timestamp)


def score_event_list(items: List[Any], preferred_key: str = "") -> int:
    if not isinstance(items, list):
        return -1
    if items == []:
        return 0

    dict_count = sum(1 for item in items if isinstance(item, dict))
    event_like_count = sum(1 for item in items if looks_like_event_entry(item))
    score = (event_like_count * 100) + dict_count

    if preferred_key.lower() in EVENT_LIST_KEYS:
        score += 25
    if preferred_key.lower() in {"history", "protokoll", "events", "event_logs"}:
        score += 25
    return score


def iter_event_list_candidates(payload: Any, path: str = "$") -> Iterator[Tuple[str, List[Any]]]:
    if isinstance(payload, list):
        yield path, payload
        for index, item in enumerate(payload):
            yield from iter_event_list_candidates(item, f"{path}[{index}]")
        return

    if isinstance(payload, dict):
        for key, value in payload.items():
            next_path = f"{path}.{key}"
            if isinstance(value, list):
                yield next_path, value
            if isinstance(value, (dict, list)):
                yield from iter_event_list_candidates(value, next_path)


def extract_event_list(payload: Any) -> Tuple[List[dict], str]:
    if isinstance(payload, list):
        if all(isinstance(item, dict) for item in payload):
            return payload, "$"
        raise ValueError("JSON-Liste enthaelt keine verarbeitbaren Event-Objekte.")

    if not isinstance(payload, dict):
        raise ValueError("JSON-Inhalt muss ein Dictionary oder eine Liste sein.")

    best_path = ""
    best_items: List[Any] = []
    best_score = -1

    for candidate_path, items in iter_event_list_candidates(payload):
        preferred_key = candidate_path.rsplit(".", 1)[-1] if "." in candidate_path else candidate_path
        score = score_event_list(items, preferred_key)
        if score > best_score:
            best_path = candidate_path
            best_items = items
            best_score = score

    filtered_events = [item for item in best_items if isinstance(item, dict)]
    if not filtered_events:
        available_keys = ", ".join(sorted(payload.keys()))
        raise ValueError(
            "Keine verarbeitbare Event-Liste im JSON gefunden. "
            f"Top-Level-Schluessel: {available_keys or '(keine)'}"
        )

    if not any(looks_like_event_entry(item) for item in filtered_events):
        raise ValueError(
            "Es wurde zwar eine Objektliste gefunden, aber kein passendes Event-Log "
            "mit Kommando/Parameter/Timestamp."
        )

    return filtered_events, best_path or "$"


def load_events(file_path: Path) -> List[dict]:
    print(f"[2/4] Lade JSON-Backup: {file_path}")
    with file_path.open("r", encoding="utf-8-sig") as handle:
        payload = json.load(handle)

    events, source_path = extract_event_list(payload)
    print(f"    -> {len(events)} moegliche Log-Eintraege gefunden")
    print(f"    -> Verwendete Event-Liste: {source_path}")
    return events


def build_swimmer_index(
    rows: List[Dict[str, str]],
    headers: List[str],
    numeric_columns: Sequence[str],
) -> Dict[str, Dict[str, str]]:
    index: Dict[str, Dict[str, str]] = {}
    for row in rows:
        nummer = normalise_number(row.get("nummer"))
        if not nummer:
            continue
        row["nummer"] = nummer
        index[nummer] = ensure_row_shape(row, headers, numeric_columns)
    return index


def create_empty_row(headers: List[str], swimmer_id: str, numeric_columns: Sequence[str]) -> Dict[str, str]:
    row: Dict[str, str] = {}
    numeric_set = set(numeric_columns)
    for header in headers:
        if header in numeric_set:
            row[header] = "0"
        else:
            row[header] = ""
    row["nummer"] = swimmer_id
    return row


def get_command(entry: Dict[str, Any]) -> str:
    for key in COMMAND_KEYS:
        value = entry.get(key)
        if value is not None:
            return str(value).strip().upper()
    return ""


def get_timestamp_value(entry: Dict[str, Any]) -> Any:
    for key in TIMESTAMP_KEYS:
        if key in entry:
            return entry[key]
    return None


def get_parameter_list(entry: Dict[str, Any]) -> Optional[List[Any]]:
    parameter = entry.get("parameter")
    if isinstance(parameter, (list, tuple)):
        return list(parameter)

    params = entry.get("params")
    if isinstance(params, (list, tuple)):
        return list(params)

    return None


def extract_swimmer_id_and_lanes(entry: Dict[str, Any]) -> Tuple[str, int]:
    parameter = get_parameter_list(entry)
    if parameter is None or not parameter:
        raise ValueError("Parameterliste fehlt")

    swimmer_id = normalise_number(parameter[0])
    if not swimmer_id:
        raise ValueError("Schwimmer-ID fehlt")

    lanes = parse_int(parameter[1], 1) if len(parameter) > 1 else 1
    return swimmer_id, lanes


def merge_events_into_rows(
    swimmer_index: Dict[str, Dict[str, str]],
    headers: List[str],
    numeric_columns: Sequence[str],
    events: List[dict],
    skip_missing_swimmers: bool,
    timeframes: Optional[List[dict]],
) -> Tuple[int, int, int]:
    print("[3/4] Verarbeite ADD-Events")
    processed = 0
    ignored = 0
    created = 0
    positive_updates = 0
    negative_updates = 0

    for entry in events:
        if not isinstance(entry, dict):
            ignored += 1
            continue

        if get_command(entry) != "ADD":
            continue

        try:
            swimmer_id, lanes = extract_swimmer_id_and_lanes(entry)
            event_time = parse_timestamp(get_timestamp_value(entry))
        except ValueError:
            ignored += 1
            continue

        bucket = bucket_for_timestamp(event_time, timeframes)

        if swimmer_id not in swimmer_index:
            if skip_missing_swimmers:
                # Unbekannte Schwimmer werden hier bewusst sicher uebersprungen.
                ignored += 1
                continue

            swimmer_index[swimmer_id] = create_empty_row(headers, swimmer_id, numeric_columns)
            created += 1

        row = swimmer_index[swimmer_id]
        new_total = parse_int(row.get("bahnanzahl"), 0) + lanes
        new_bucket_total = parse_int(row.get(bucket), 0) + lanes
        row["bahnanzahl"] = str(max(new_total, 0))
        row[bucket] = str(max(new_bucket_total, 0))

        if lanes < 0:
            negative_updates += 1
        else:
            positive_updates += 1
        processed += 1

    print(f"    -> {processed} ADD-Events verarbeitet")
    print(f"    -> {positive_updates} positive und {negative_updates} negative Bahn-Aenderungen")
    print(f"    -> {created} neue Schwimmer aus JSON angelegt")
    print(f"    -> {ignored} Eintraege uebersprungen")
    if processed == 0:
        print("    -> Hinweis: Es wurden keine ADD-Events gefunden, die verarbeitet werden konnten.")
    return processed, created, ignored


def write_csv(
    file_path: Path,
    headers: List[str],
    rows: Iterable[Dict[str, str]],
    delimiter: str,
    numeric_columns: Sequence[str],
) -> None:
    print(f"[4/4] Schreibe Ergebnisdatei: {file_path}")
    sorted_rows = sorted(
        (ensure_row_shape(row, headers, numeric_columns) for row in rows),
        key=lambda item: (parse_int(item.get("nummer"), 10**12), item.get("nummer", "")),
    )

    file_path.parent.mkdir(parents=True, exist_ok=True)
    with file_path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers, delimiter=delimiter)
        writer.writeheader()
        writer.writerows(sorted_rows)

    print(f"    -> {len(sorted_rows)} Zeilen geschrieben")


def build_output_headers(original_headers: List[str]) -> List[str]:
    unique_original_headers = []
    for header in original_headers:
        if header and header not in unique_original_headers:
            unique_original_headers.append(header)

    headers = [header for header in EXPECTED_COLUMNS if header in unique_original_headers]
    headers.extend(header for header in EXPECTED_COLUMNS if header not in headers)
    headers.extend(header for header in unique_original_headers if header not in headers)
    return headers


def main() -> int:
    args = parse_args()

    csv_path = resolve_input_path(args.csv, DEFAULT_INPUT_CSV_NAMES, "CSV-Datei")
    json_path = resolve_input_path(args.json_path, DEFAULT_INPUT_JSON_NAMES, "JSON-Datei")
    output_path = build_output_path(csv_path, args.output)

    rows, original_headers, delimiter = load_csv_rows(csv_path)
    output_headers = build_output_headers(original_headers)

    timeframes: Optional[List[dict]] = None
    timeframe_names: List[str] = []
    if args.timeframes:
        tf_path = Path(args.timeframes).expanduser()
        if not tf_path.is_file():
            raise FileNotFoundError(f"Zeitfenster-Datei nicht gefunden: {tf_path}")
        print(f"[0/4] Lade Zeitfenster: {tf_path}")
        timeframes = load_timeframes(tf_path)
        timeframe_names = [str(frame["name"]) for frame in timeframes]
        print(f"    -> {len(timeframes)} aktive Zeitfenster geladen")

    for name in timeframe_names:
        if name not in output_headers:
            output_headers.append(name)

    numeric_columns: List[str] = list(NUMERIC_COLUMNS)
    for name in timeframe_names:
        if name not in numeric_columns:
            numeric_columns.append(name)

    if args.reset_calculation_columns:
        reset_calculation_columns(rows, numeric_columns)

    swimmer_index = build_swimmer_index(rows, output_headers, numeric_columns)
    events = load_events(json_path)
    merge_events_into_rows(swimmer_index, output_headers, numeric_columns, events, args.skip_missing_swimmers, timeframes)
    write_csv(output_path, output_headers, swimmer_index.values(), delimiter, numeric_columns)

    print(f"Fertig. Die kombinierte Datei wurde erfolgreich erstellt: {output_path}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"Fehler: {exc}")
        raise SystemExit(1)
