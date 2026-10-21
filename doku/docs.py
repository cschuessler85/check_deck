"""
@doc-file
@doc-id tools.build_docs
@doc-kind cli-script
@doc-purpose Extrahiert @doc-Marker aus dem Quellcode und erzeugt eine vollstaendige, offline-faehige Single-File-HTML-Dokumentation mit Suchfunktion.
@doc-entry CLI-Aufruf ueber Python (python build_docs.py [projekt_pfad] [ausgabe_datei.html])
@doc-input Quellcodedateien (.php, .js, .py, .css, .html) mit @doc-Kommentarbloecken
@doc-output Alleinlaufaehige HTML-Datei (Standard: DOKUMENTATION.html) mit Bedienungsanleitung und Softwaredoku
@doc-side-effects Liest Projektdateien rekursiv und schreibt eine HTML-Datei ins Dateisystem
"""

import html
import re
import sys
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List

# @doc-section config
# @doc-purpose Definiert Ausschlussregeln, erlaubte Dateiendungen und Listen-Marker gemaess Standard.
EXCLUDED_DIRS = {"vendor", ".git", "node_modules", "__pycache__", "logs", "dumps", "dist", "build"}
ALLOWED_EXTENSIONS = {".php", ".js", ".py", ".css", ".html"}

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
    @doc-purpose Prueft, ob eine Datei laut Kommentarstandard von der Extraktion ausgeschlossen ist.
    @doc-param Path file_path Zu pruefender Dateipfad.
    @doc-return bool True bei Ausschluss (z. B. vendor, *.min.js oder nicht unterstuetzte Dateiendung).

    @param Path file_path Zu pruefender Dateipfad.
    @return bool True bei Ausschluss.
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
    @doc-purpose Liest eine Quellcodedatei und fasst zusammenhaengende @doc-Zeilen zu Doku-Objekten zusammen.
    @doc-param Path file_path Pfad zur Quellcodedatei.
    @doc-return list Liste der extrahierten Doku-Bloecke als Dictionaries.

    @param Path file_path Pfad zur Quellcodedatei.
    @return list Liste der extrahierten Doku-Bloecke.
    """
    blocks: List[Dict[str, Any]] = []
    current_block: Dict[str, Any] = {}
    in_comment_group = False

    tag_pattern = re.compile(r"@(doc-[a-zA-Z0-9_-]+)(?:\s+(.*))?$")

    try:
        lines = file_path.read_text(encoding="utf-8", errors="ignore").splitlines()
    except Exception:
        return blocks

    for line in lines:
        stripped = line.strip()
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
    @doc-purpose Durchsucht das Projektverzeichnis rekursiv und gruppiert Doku-Bloecke nach Modulen.
    @doc-param Path root_dir Wurzelverzeichnis des zu dokumentierenden Projekts.
    @doc-return list Strukturierte Liste aller gefundenen Module samt Sektionen, Funktionen und UI-Flows.

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
            "file_path": str(path.relative_to(root_dir)).replace("\\", "/"),
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


def esc(text: Any) -> str:
    """
    @doc-function tools.build_docs.esc
    @doc-purpose Maskiert HTML-Sonderzeichen fuer eine sichere Darstellung im Browser.
    @doc-param Any text Eingabewert.
    @doc-return str HTML-maskierter String.

    @param Any text Eingabewert.
    @return str HTML-maskierter String.
    """
    if text is None or text is True:
        return ""
    return html.escape(str(text))


def render_html(modules: List[Dict[str, Any]]) -> str:
    """
    @doc-function tools.build_docs.render_html
    @doc-purpose Baut aus den geparsten Modulen ein einzelnes, selbststaendiges HTML5-Dokument mit Live-Suche und Tab-Navigation.
    @doc-param list modules Liste der geparsten Modul-Dictionaries.
    @doc-return str Vollstaendiger HTML-Code als String.

    @param list modules Liste der geparsten Modul-Dictionaries.
    @return str Vollstaendiger HTML-Code.
    """
    now_str = datetime.now().strftime("%d.%m.%Y %H:%M")

    # Navigation und Content fuer Bedienungsanleitung (UI-Flows)
    manual_nav_items: List[str] = []
    manual_cards: List[str] = []
    flow_count = 0

    for mod in modules:
        mod_id = mod["meta"].get("doc-id", mod["file_path"])
        for flow in mod["ui_flows"]:
            flow_count += 1
            flow_id = str(flow.get("doc-ui-flow", f"flow-{flow_count}"))
            anchor = "flow-" + re.sub(r"[^a-zA-Z0-9_-]", "-", flow_id)
            purpose = flow.get("doc-purpose", "")

            manual_nav_items.append(
                f'<li><a href="#{anchor}" class="nav-link" data-tab-target="manual">{esc(flow_id)}</a></li>'
            )

            steps = flow.get("doc-ui-step", [])
            targets = flow.get("doc-ui-target", [])
            results = flow.get("doc-ui-result", [])

            steps_html = []
            if steps:
                for idx, step in enumerate(steps):
                    t_badge = (
                        f'<span class="badge badge-target">Ziel: <code>{esc(targets[idx])}</code></span>'
                        if idx < len(targets)
                        else ""
                    )
                    r_box = (
                        f'<div class="step-result">&rarr; <strong>Ergebnis:</strong> {esc(results[idx])}</div>'
                        if idx < len(results)
                        else ""
                    )
                    steps_html.append(
                        f"""
                        <div class="ui-step">
                            <div class="step-header">
                                <span class="step-num">Schritt {idx + 1}</span>
                                <span class="step-action">{esc(step)}</span>
                                {t_badge}
                            </div>
                            {r_box}
                        </div>
                        """
                    )
            elif targets or results:
                t_info = f"<p><strong>Element:</strong> <code>{esc(', '.join(targets))}</code></p>" if targets else ""
                r_info = f"<p><strong>Ergebnis:</strong> {esc(', '.join(results))}</p>" if results else ""
                steps_html.append(f'<div class="ui-step">{t_info}{r_info}</div>')

            manual_cards.append(
                f"""
                <article class="doc-card searchable" id="{anchor}">
                    <div class="card-header">
                        <span class="badge badge-flow">Click-to-Click Flow</span>
                        <span class="badge badge-file">{esc(mod_id)}</span>
                    </div>
                    <h2>{esc(flow_id)}</h2>
                    {f'<p class="purpose">{esc(purpose)}</p>' if purpose else ""}
                    <div class="steps-container">
                        {"".join(steps_html)}
                    </div>
                </article>
                """
            )

    if not manual_cards:
        manual_cards.append(
            '<div class="empty-state">Noch keine <code>@doc-ui-flow</code>-Marker im Projekt gefunden.</div>'
        )

    # Navigation und Content fuer Technische Softwaredokumentation
    tech_nav_items: List[str] = []
    tech_cards: List[str] = []

    for idx, mod in enumerate(modules):
        meta = mod["meta"]
        mod_id = str(meta.get("doc-id", mod["file_path"]))
        kind = str(meta.get("doc-kind", "module"))
        purpose = str(meta.get("doc-purpose", "Keine Beschreibung vorhanden."))
        anchor = "mod-" + re.sub(r"[^a-zA-Z0-9_-]", "-", mod_id) + f"-{idx}"

        tech_nav_items.append(
            f'<li><a href="#{anchor}" class="nav-link" data-tab-target="tech">{esc(mod_id)}</a></li>'
        )

        # Modulkopf-Metadaten
        meta_rows = []
        for label, key in [
            ("Einstieg (@doc-entry)", "doc-entry"),
            ("Eingaben (@doc-input)", "doc-input"),
            ("Ausgaben (@doc-output)", "doc-output"),
            ("Seiteneffekte (@doc-side-effects)", "doc-side-effects"),
            ("Quelle (@doc-source)", "doc-source"),
            ("Hinweise (@doc-notes)", "doc-notes"),
        ]:
            if key in meta:
                meta_rows.append(f"<tr><th>{esc(label)}</th><td>{esc(meta[key])}</td></tr>")

        if "doc-see" in meta:
            see_list = ", ".join(esc(s) for s in meta["doc-see"])
            meta_rows.append(f"<tr><th>Siehe auch (@doc-see)</th><td><code>{see_list}</code></td></tr>")

        meta_table = (
            f'<table class="meta-table"><tbody>{"".join(meta_rows)}</tbody></table>' if meta_rows else ""
        )

        # Ablauf-Sektionen
        sections_html = []
        for sec in mod["sections"]:
            sec_id = sec.get("doc-section", "Abschnitt")
            sec_details = []
            for s_label, s_key in [
                ("Zweck", "doc-purpose"),
                ("Ausloeser", "doc-trigger"),
                ("Eingabe", "doc-input"),
                ("Ausgabe", "doc-output"),
                ("Seiteneffekte", "doc-side-effects"),
            ]:
                if s_key in sec:
                    sec_details.append(f"<li><strong>{esc(s_label)}:</strong> {esc(sec[s_key])}</li>")

            steps_list = ""
            if "doc-step" in sec:
                li_steps = "".join(f"<li>{esc(st)}</li>" for st in sec["doc-step"])
                steps_list = f'<div class="sub-label">Ablaufschritte:</div><ol class="step-list">{li_steps}</ol>'

            sections_html.append(
                f"""
                <div class="sub-block section-block">
                    <h4>Abschnitt: <code>{esc(sec_id)}</code></h4>
                    <ul class="detail-list">{"".join(sec_details)}</ul>
                    {steps_list}
                </div>
                """
            )

        # Funktionen
        functions_html = []
        for fn in mod["functions"]:
            fn_id = fn.get("doc-function", "Unbenannt")
            fn_purpose = fn.get("doc-purpose", "")

            params_html = ""
            if "doc-param" in fn:
                p_items = "".join(f"<li><code>{esc(p)}</code></li>" for p in fn["doc-param"])
                params_html = f'<div class="sub-label">Parameter:</div><ul class="code-list">{p_items}</ul>'

            ret_html = (
                f'<div class="sub-label">Rueckgabe: <code>{esc(fn["doc-return"])}</code></div>'
                if "doc-return" in fn
                else ""
            )

            throws_html = ""
            if "doc-throws" in fn:
                t_items = "".join(f"<li><code>{esc(t)}</code></li>" for t in fn["doc-throws"])
                throws_html = f'<div class="sub-label">Exceptions:</div><ul class="code-list">{t_items}</ul>'

            notes_html = ""
            if "doc-note" in fn:
                n_items = "".join(f'<div class="note-box"><strong>Hinweis:</strong> {esc(n)}</div>' for n in fn["doc-note"])
                notes_html = n_items

            functions_html.append(
                f"""
                <div class="sub-block fn-block">
                    <h4>Funktion: <code>{esc(fn_id)}</code></h4>
                    {f"<p>{esc(fn_purpose)}</p>" if fn_purpose else ""}
                    {params_html}
                    {ret_html}
                    {throws_html}
                    {notes_html}
                </div>
                """
            )

        sec_wrapper = (
            f'<div class="block-group"><h3>Logikabschnitte &amp; Ablaeufe</h3>{"".join(sections_html)}</div>'
            if sections_html
            else ""
        )
        fn_wrapper = (
            f'<div class="block-group"><h3>Funktionen</h3>{"".join(functions_html)}</div>'
            if functions_html
            else ""
        )

        tech_cards.append(
            f"""
            <article class="doc-card searchable" id="{anchor}">
                <div class="card-header">
                    <span class="badge badge-kind">{esc(kind)}</span>
                    <span class="badge badge-file">{esc(mod["file_path"])}</span>
                </div>
                <h2>{esc(mod_id)}</h2>
                <p class="purpose">{esc(purpose)}</p>
                {meta_table}
                {sec_wrapper}
                {fn_wrapper}
            </article>
            """
        )

    if not tech_cards:
        tech_cards.append(
            '<div class="empty-state">Noch keine <code>@doc-file</code>-Marker im Projekt gefunden.</div>'
        )

    return f"""<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Softwaredokumentation &amp; Bedienungsanleitung</title>
    <style>
        :root {{
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text: #0f172a;
            --text-muted: #475569;
            --primary: #0284c7;
            --primary-light: #e0f2fe;
            --accent: #059669;
            --accent-light: #d1fae5;
            --code-bg: #f1f5f9;
        }}
        * {{ box-sizing: border-box; margin: 0; padding: 0; }}
        body {{
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }}
        header {{
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            justify-content: space-between;
        }}
        .brand h1 {{ font-size: 1.25rem; font-weight: 700; }}
        .brand span {{ font-size: 0.8rem; color: var(--text-muted); }}
        .controls {{
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }}
        .search-box {{
            padding: 0.5rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.9rem;
            min-width: 260px;
            outline: none;
        }}
        .search-box:focus {{ border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-light); }}
        .tab-group {{ display: flex; background: var(--code-bg); padding: 0.25rem; border-radius: 8px; gap: 0.25rem; }}
        .tab-btn {{
            border: none;
            background: transparent;
            padding: 0.45rem 0.9rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
        }}
        .tab-btn.active {{ background: var(--surface); color: var(--primary); box-shadow: 0 1px 2px rgba(0,0,0,0.06); }}
        .layout {{
            display: grid;
            grid-template-columns: 280px 1fr;
            flex: 1;
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
        }}
        aside {{
            background: var(--surface);
            border-right: 1px solid var(--border);
            padding: 1.25rem;
            height: calc(100vh - 69px);
            position: sticky;
            top: 69px;
            overflow-y: auto;
        }}
        .nav-group-title {{
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin: 1rem 0 0.5rem;
            font-weight: 700;
        }}
        .nav-group-title:first-child {{ margin-top: 0; }}
        aside ul {{ list-style: none; }}
        aside li {{ margin-bottom: 0.25rem; }}
        .nav-link {{
            display: block;
            padding: 0.35rem 0.6rem;
            border-radius: 5px;
            color: var(--text);
            text-decoration: none;
            font-size: 0.85rem;
            word-break: break-all;
        }}
        .nav-link:hover {{ background: var(--code-bg); color: var(--primary); }}
        main {{ padding: 1.5rem 2rem; max-width: 1000px; }}
        .section-heading {{
            font-size: 1.4rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--border);
        }}
        .doc-card {{
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }}
        .card-header {{ display: flex; gap: 0.5rem; margin-bottom: 0.6rem; flex-wrap: wrap; }}
        .badge {{
            font-size: 0.75rem;
            padding: 0.15rem 0.55rem;
            border-radius: 4px;
            font-weight: 600;
        }}
        .badge-kind {{ background: var(--primary-light); color: var(--primary); }}
        .badge-flow {{ background: var(--accent-light); color: var(--accent); }}
        .badge-file {{ background: var(--code-bg); color: var(--text-muted); font-family: monospace; }}
        .badge-target {{ background: #fef3c7; color: #b45309; }}
        .doc-card h2 {{ font-size: 1.25rem; margin-bottom: 0.4rem; word-break: break-all; }}
        .purpose {{ color: var(--text-muted); margin-bottom: 1rem; font-size: 0.95rem; }}
        .meta-table {{
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
        }}
        .meta-table th, .meta-table td {{
            border: 1px solid var(--border);
            padding: 0.5rem 0.75rem;
            text-align: left;
            vertical-align: top;
        }}
        .meta-table th {{ width: 240px; background: var(--code-bg); font-weight: 600; }}
        .block-group {{ margin-top: 1.25rem; }}
        .block-group h3 {{ font-size: 1.05rem; margin-bottom: 0.75rem; color: var(--text); }}
        .sub-block {{
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
        }}
        .sub-block h4 {{ font-size: 0.95rem; margin-bottom: 0.5rem; }}
        .detail-list, .code-list, .step-list {{ padding-left: 1.25rem; font-size: 0.9rem; margin-top: 0.35rem; }}
        .sub-label {{ font-size: 0.85rem; font-weight: 600; margin-top: 0.6rem; }}
        .note-box {{
            margin-top: 0.6rem;
            padding: 0.5rem 0.75rem;
            background: #fffbeb;
            border-left: 3px solid #f59e0b;
            font-size: 0.85rem;
        }}
        .ui-step {{
            border-left: 3px solid var(--accent);
            background: var(--bg);
            padding: 0.75rem 1rem;
            margin-bottom: 0.6rem;
            border-radius: 0 6px 6px 0;
        }}
        .step-header {{ display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }}
        .step-num {{
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: var(--accent);
        }}
        .step-action {{ font-weight: 600; }}
        .step-result {{ margin-top: 0.35rem; font-size: 0.9rem; color: var(--text-muted); }}
        code {{
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            background: var(--code-bg);
            padding: 0.1rem 0.35rem;
            border-radius: 4px;
            font-size: 0.85em;
        }}
        .hidden {{ display: none !important; }}
        .empty-state {{
            padding: 2rem;
            text-align: center;
            background: var(--surface);
            border: 1px dashed var(--border);
            border-radius: 8px;
            color: var(--text-muted);
        }}
        @media (max-width: 900px) {{
            .layout {{ grid-template-columns: 1fr; }}
            aside {{ height: auto; position: static; border-right: none; border-bottom: 1px solid var(--border); }}
        }}
        @media print {{
            header, aside {{ display: none !important; }}
            .layout {{ display: block; }}
            main {{ max-width: 100%; padding: 0; }}
            .tab-pane {{ display: block !important; }}
            .doc-card {{ break-inside: avoid; box-shadow: none; }}
        }}
    </style>
</head>
<body>
    <header>
        <div class="brand">
            <h1>Projektdokumentation</h1>
            <span>Stand: {now_str} &bull; {len(modules)} Module &bull; {flow_count} Bedienablaeufe</span>
        </div>
        <div class="controls">
            <input type="search" id="search-input" class="search-box" placeholder="Suche nach Modul, Funktion, Button, Text...">
            <div class="tab-group" id="tab-controls">
                <button type="button" class="tab-btn active" data-tab="all">Alle anzeigen</button>
                <button type="button" class="tab-btn" data-tab="manual">Bedienungsanleitung ({flow_count})</button>
                <button type="button" class="tab-btn" data-tab="tech">Softwaredoku ({len(modules)})</button>
            </div>
        </div>
    </header>
    <div class="layout">
        <aside>
            <div class="nav-group-title">Bedienungsanleitung</div>
            <ul>{"".join(manual_nav_items) or "<li><small>Keine Flows</small></li>"}</ul>
            <div class="nav-group-title">Module &amp; Dateien</div>
            <ul>{"".join(tech_nav_items) or "<li><small>Keine Module</small></li>"}</ul>
        </aside>
        <main>
            <section id="pane-manual" class="tab-pane">
                <h2 class="section-heading">Bedienungsanleitung (Click-to-Click)</h2>
                {"".join(manual_cards)}
            </section>
            <section id="pane-tech" class="tab-pane">
                <h2 class="section-heading">Technische Softwaredokumentation</h2>
                {"".join(tech_cards)}
            </section>
        </main>
    </div>
    <script>
        /**
         * @doc-ui-flow docs.interaktive_suche_und_tabs
         * @doc-purpose Steuert Volltextsuche und Tab-Filterung im generierten Offline-HTML-Handbuch.
         * @doc-ui-step 1 Suchbegriff im Suchfeld eingeben
         * @doc-ui-target #search-input
         * @doc-ui-result Nur passende Module und Click-to-Click-Anleitungen bleiben sichtbar
         * @doc-ui-step 2 Ansicht ueber die Tab-Buttons wechseln
         * @doc-ui-target #tab-controls
         * @doc-ui-result Schaltet zwischen Gesamtschau, Bedienungsanleitung und Softwaredoku um
         */
        document.addEventListener("DOMContentLoaded", () => {{
            const searchInput = document.getElementById("search-input");
            const tabButtons = document.querySelectorAll(".tab-btn");
            const paneManual = document.getElementById("pane-manual");
            const paneTech = document.getElementById("pane-tech");
            const cards = document.querySelectorAll(".searchable");

            let activeTab = "all";

            function updateView() {{
                const query = searchInput.value.trim().toLowerCase();

                paneManual.classList.toggle("hidden", activeTab === "tech");
                paneTech.classList.toggle("hidden", activeTab === "manual");

                cards.forEach(card => {{
                    const matchesQuery = !query || card.textContent.toLowerCase().includes(query);
                    card.classList.toggle("hidden", !matchesQuery);
                }});
            }}

            searchInput.addEventListener("input", updateView);

            tabButtons.forEach(btn => {{
                btn.addEventListener("click", () => {{
                    tabButtons.forEach(b => b.classList.remove("active"));
                    btn.classList.add("active");
                    activeTab = btn.getAttribute("data-tab");
                    updateView();
                }});
            }});

            document.querySelectorAll(".nav-link").forEach(link => {{
                link.addEventListener("click", () => {{
                    const targetTab = link.getAttribute("data-tab-target");
                    if (activeTab !== "all" && activeTab !== targetTab) {{
                        const targetBtn = document.querySelector(`.tab-btn[data-tab="${{targetTab}}"]`);
                        if (targetBtn) targetBtn.click();
                    }}
                }});
            }});
        }});
    </script>
</body>
</html>"""


if __name__ == "__main__":
    target_dir = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(".")
    output_file = Path(sys.argv[2]) if len(sys.argv) > 2 else Path("DOKUMENTATION.html")

    parsed_modules = parse_project(target_dir)
    output_file.write_text(render_html(parsed_modules), encoding="utf-8")
    print(f"Offline-HTML-Dokumentation erfolgreich erstellt: {output_file} ({len(parsed_modules)} Module)")
