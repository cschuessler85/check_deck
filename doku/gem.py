"""
@doc-file
@doc-id tools.build_docs
@doc-kind cli-script
@doc-purpose Extrahiert @doc-Marker aus dem Quellcode und generiert Softwaredokumentation sowie Bedienungsanleitung als Markdown.
@doc-entry CLI-Aufruf ueber Python (python build_docs.py <projekt_pfad>)
@doc-input Quellcodedateien im Projektverzeichnis mit @doc-Kommentarbloecken
@doc-output SOFTWARE-DOKU.md und BEDIENUNGSANLEITUNG.md
@doc-side-effects Liest Projektdateien rekursiv und schreibt zwei Markdown-Dateien ins Arbeitsverzeichnis
/**
@doc-ui-flow teilnehmer.anlegen
@doc-purpose Neuen Teilnehmer ueber die Schnellmaske erfassen.
@doc-ui-step 1 Klick auf "Neuer Teilnehmer"
@doc-ui-target #btn-new-participant
@doc-ui-result Eingabemodal oeffnet sich
@doc-ui-step 2 Pflichtfelder ausfuellen und "Speichern" klicken
@doc-ui-target #form-participant-submit
@doc-ui-result Teilnehmer erscheint in der Tabelle und Startnummer wird vergeben
"""

import os
import re
import sys
from pathlib import Path
from typing import Dict, List, Any

# Ausgeschlossene Ordner und Dateimuster gemaess Standard
EXCLUDED_DIRS = {"vendor", ".git", "node_modules", "__pycache__", "logs", "dumps"}
ALLOWED_EXTENSIONS = {".php", ".js", ".py", ".css", ".html"}

# Felder, die mehrfach in einem Block vorkommen koennen und als Liste gesammelt werden
LIST_TAGS = {
    "doc-step",
    "doc-ui-step",
    "doc-ui-target",
    "doc-ui-result",
    "doc-param",
    "doc-throws",
    "doc-note",
    "doc-see",
}


def is_excluded(file_path: Path) -> bool:
    """
    @doc-function tools.build_docs.is_excluded
    @doc-purpose Prueft, ob eine Datei gemaess Kommentarstandard ignoriert werden muss.
    @doc-param Path file_path Zu pruefender Dateipfad.
    @doc-return bool True, wenn die Datei ausgeschlossen ist (z.B. vendor oder *.min.js).

    @param Path file_path Zu pruefender Dateipfad.
    @return bool True, wenn die Datei ausgeschlossen ist.
    """
    if any(part in EXCLUDED_DIRS for part in file_path.parts):
        return True
    if file_path.name.endswith(".min.js") or file_path.name.endswith(".min.css"):
        return True
    if file_path.suffix.lower() not in ALLOWED_EXTENSIONS:
        return True
    return False


def extract_doc_blocks(file_path: Path) -> List[Dict[str, Any]]:
    """
    @doc-function tools.build_docs.extract_doc_blocks
    @doc-purpose Liest eine Datei zeilenweise und gruppiert zusammenhaengende @doc-Zeilen zu Dokuobjekten.
    @doc-param Path file_path Pfad zur Quellcodedatei.
    @doc-return list Liste aller extrahierten Doku-Bloecke als Dictionaries.

    @param Path file_path Pfad zur Quellcodedatei.
    @return list Liste aller extrahierten Doku-Bloecke.
    """
    blocks: List[Dict[str, Any]] = []
    current_block: Dict[str, Any] = {}
    in_comment_group = False

    # Matcht Zeilen mit @doc-<tag> [optionaler wert]
    tag_pattern = re.compile(r"@(doc-[a-zA-Z0-9_-]+)(?:\s+(.*))?$")

    try:
        lines = file_path.read_text(encoding="utf-8", errors="ignore").splitlines()
    except Exception:
        return blocks

    for line in lines:
        stripped = line.strip()

        # Pruefen, ob die Zeile Teil eines Kommentars oder Docstrings ist
        is_comment_line = (
            stripped.startswith("*")
            or stripped.startswith("//")
            or stripped.startswith("#")
            or stripped.startswith("/*")
            or stripped.startswith('"""')
            or stripped.startswith("'''")
            or "@doc-" in stripped
        )

        match = tag_pattern.search(stripped)
        if match:
            in_comment_group = True
            tag_name = match.group(1)
            tag_value = (match.group(2) or "").strip()

            if tag_name in LIST_TAGS:
                current_block.setdefault(tag_name, []).append(tag_value)
            else:
                current_block[tag_name] = tag_value if tag_value else True
        else:
            # Wenn ein Kommentarblock endet, wird das gesammelte Dokuobjekt gespeichert
            if in_comment_group and not is_comment_line:
                if current_block:
                    blocks.append(current_block)
                    current_block = {}
                in_comment_group = False
            elif in_comment_group and stripped in {"*/", '"""', "'''"}:
                if current_block:
                    blocks.append(current_block)
                    current_block = {}
                in_comment_group = False

    if current_block:
        blocks.append(current_block)

    return blocks


def parse_project(root_dir: Path) -> List[Dict[str, Any]]:
    """
    @doc-function tools.build_docs.parse_project
    @doc-purpose Durchlaeuft das Projektverzeichnis und ordnet Sektionen, UI-Flows und Funktionen ihren Modulen zu.
    @doc-param Path root_dir Wurzelverzeichnis des Projekts.
    @doc-return list Strukturierte Liste aller Module inklusive Unterabschnitten.

    @param Path root_dir Wurzelverzeichnis des Projekts.
    @return list Strukturierte Liste aller Module.
    """
    modules: List[Dict[str, Any]] = []

    for path in sorted(root_dir.rglob("*")):
        if not path.is_file() or is_excluded(path):
            continue

        blocks = extract_doc_blocks(path)
        if not blocks:
            continue

        current_module: Dict[str, Any] = {
            "file_path": str(path.relative_to(root_dir)),
            "meta": {},
            "sections": [],
            "functions": [],
            "ui_flows": [],
        }

        for block in blocks:
            if "doc-file" in block:
                current_module["meta"] = block
            elif "doc-section" in block:
                current_module["sections"].append(block)
            elif "doc-function" in block:
                current_module["functions"].append(block)
            elif "doc-ui-flow" in block:
                current_module["ui_flows"].append(block)

        if (
            current_module["meta"]
            or current_module["sections"]
            or current_module["functions"]
            or current_module["ui_flows"]
        ):
            modules.append(current_module)

    return modules


def generate_software_docs(modules: List[Dict[str, Any]]) -> str:
    """
    @doc-function tools.build_docs.generate_software_docs
    @doc-purpose Erzeugt den Markdown-Inhalt fuer die technische Softwaredokumentation.
    @doc-param list modules Liste der geparsten Modul-Dictionaries.
    @doc-return str Fertig formatierter Markdown-String fuer SOFTWARE-DOKU.md.

    @param list modules Liste der geparsten Modul-Dictionaries.
    @return str Fertig formatierter Markdown-String.
    """
    md = ["# Technische Softwaredokumentation\n"]

    for mod in modules:
        meta = mod["meta"]
        mod_id = meta.get("doc-id", mod["file_path"])
        kind = meta.get("doc-kind", "unbekannt")
        purpose = meta.get("doc-purpose", "-")

        md.append(f"## Modul: `{mod_id}` ({kind})")
        md.append(f"**Datei:** `{mod['file_path']}`  ")
        md.append(f"**Zweck:** {purpose}\n")

        if meta:
            md.append("| Eigenschaft | Beschreibung |")
            md.append("| :--- | :--- |")
            md.append(f"| **Einstieg (`@doc-entry`)** | {meta.get('doc-entry', '-')} |")
            md.append(f"| **Eingaben (`@doc-input`)** | {meta.get('doc-input', '-')} |")
            md.append(f"| **Ausgaben (`@doc-output`)** | {meta.get('doc-output', '-')} |")
            md.append(f"| **Seiteneffekte (`@doc-side-effects`)** | {meta.get('doc-side-effects', '-')} |")
            md.append("")

        # Ablauf-Sektionen
        if mod["sections"]:
            md.append("### Logikabschnitte & Abläufe")
            for sec in mod["sections"]:
                sec_id = sec.get("doc-section", "Abschnitt")
                md.append(f"#### Abschnitt `{sec_id}`")
                if "doc-purpose" in sec:
                    md.append(f"- **Zweck:** {sec['doc-purpose']}")
                if "doc-trigger" in sec:
                    md.append(f"- **Auslöser:** {sec['doc-trigger']}")
                if "doc-input" in sec:
                    md.append(f"- **Eingabe:** {sec['doc-input']}")
                if "doc-output" in sec:
                    md.append(f"- **Ausgabe:** {sec['doc-output']}")
                if "doc-side-effects" in sec:
                    md.append(f"- **Seiteneffekte:** {sec['doc-side-effects']}")
                if "doc-step" in sec:
                    md.append("\n**Ablaufschritte:**")
                    for step in sec["doc-step"]:
                        md.append(f"1. {step}")
                md.append("")

        # Funktionen
        if mod["functions"]:
            md.append("### Funktionen")
            for fn in mod["functions"]:
                fn_id = fn.get("doc-function", "Unbenannt")
                md.append(f"#### `{fn_id}`")
                md.append(f"{fn.get('doc-purpose', '')}\n")
                if "doc-param" in fn:
                    md.append("**Parameter:**")
                    for param in fn["doc-param"]:
                        md.append(f"- `{param}`")
                if "doc-return" in fn:
                    md.append(f"\n**Rückgabe:** `{fn['doc-return']}`")
                if "doc-throws" in fn:
                    md.append("\n**Exceptions:**")
                    for exc in fn["doc-throws"]:
                        md.append(f"- `{exc}`")
                if "doc-note" in fn:
                    for note in fn["doc-note"]:
                        md.append(f"\n> **Hinweis:** {note}")
                md.append("")

        md.append("---\n")

    return "\n".join(md)


def generate_user_manual(modules: List[Dict[str, Any]]) -> str:
    """
    @doc-function tools.build_docs.generate_user_manual
    @doc-purpose Erzeugt eine Click-to-Click-Bedienungsanleitung aus allen @doc-ui-flow-Bloecken.
    @doc-param list modules Liste der geparsten Modul-Dictionaries.
    @doc-return str Fertig formatierter Markdown-String fuer BEDIENUNGSANLEITUNG.md.

    @param list modules Liste der geparsten Modul-Dictionaries.
    @return str Fertig formatierter Markdown-String.
    """
    md = ["# Bedienungsanleitung (Click-to-Click)\n"]
    has_flows = False

    for mod in modules:
        if not mod["ui_flows"]:
            continue
        has_flows = True
        mod_id = mod["meta"].get("doc-id", mod["file_path"])

        for flow in mod["ui_flows"]:
            flow_id = flow.get("doc-ui-flow", "Bedienablauf")
            purpose = flow.get("doc-purpose", "")
            md.append(f"## Ablauf: {flow_id}")
            md.append(f"*Modul: `{mod_id}`*\n")
            if purpose:
                md.append(f"{purpose}\n")

            steps = flow.get("doc-ui-step", [])
            targets = flow.get("doc-ui-target", [])
            results = flow.get("doc-ui-result", [])

            if steps:
                md.append("### Schritt-für-Schritt-Anleitung")
                for idx, step in enumerate(steps):
                    target_info = f" *(Element: `{targets[idx]}`)*" if idx < len(targets) else ""
                    result_info = f" → **Ergebnis:** {results[idx]}" if idx < len(results) else ""
                    md.append(f"- **Schritt:** {step}{target_info}{result_info}")
                md.append("")

            # Falls nur ein einzelnes Ziel/Ergebnis fuer den gesamten Flow angegeben wurde
            if not steps and (targets or results):
                if targets:
                    md.append(f"- **Betroffenes Element:** `{', '.join(targets)}`")
                if results:
                    md.append(f"- **Erwartetes Ergebnis:** {', '.join(results)}")
                md.append("")

            md.append("---\n")

    if not has_flows:
        md.append("*Noch keine `@doc-ui-flow`-Marker im Projekt vorhanden.*")

    return "\n".join(md)


if __name__ == "__main__":
    target_dir = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(".")
    parsed_modules = parse_project(target_dir)

    Path("SOFTWARE-DOKU.md").write_text(generate_software_docs(parsed_modules), encoding="utf-8")
    Path("BEDIENUNGSANLEITUNG.md").write_text(generate_user_manual(parsed_modules), encoding="utf-8")
    print(f"Doku erfolgreich fuer {len(parsed_modules)} Module generiert.")