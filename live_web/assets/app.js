/**
 * @doc-file
 * @doc-id live_web.assets.app
 * @doc-kind frontend-controller
 * @doc-purpose Initialisiert globale UI-Helfer, Modale, Tabellenwerkzeuge und AJAX-Aktionen der Weboberflaeche.
 * @doc-entry Browser laedt die zentrale Frontend-Datei.
 * @doc-input DOM, Fetch-Endpunkte, LocalStorage und CSS-Klassen.
 * @doc-output Interaktive Bedienlogik fuer Admin- und Dashboard-Seiten.
 * @doc-side-effects Veraendert DOM, sendet HTTP-Requests und speichert UI-Zustand im Browser.
 */
window.appBack = (fallbackUrl) => {
  try {
    if (window.history && window.history.length > 1) {
      window.history.back();
      return;
    }
  } catch (e) {
  }
  if (fallbackUrl) {
    window.location.href = String(fallbackUrl);
  }
};

document.addEventListener('DOMContentLoaded', () => {
  // @doc-section alerts
  // @doc-purpose Steuert das automatische Ausblenden neuer und bestehender Statusmeldungen.
  // @doc-ui-flow alerts-autohide
  // @doc-ui-step 1 Eine Meldung mit der Klasse .alert erscheint im DOM.
  // @doc-ui-step 2 Das Skript markiert die Meldung als verarbeitet und blendet sie zeitverzoegert aus.
  // @doc-ui-result Kurzlebige Hinweise verschwinden automatisch, waehrend no-autohide-Meldungen stehen bleiben.
  const hideAlert = (alert) => {
    if (alert.classList.contains('no-autohide')) return;
    if (alert.dataset.autohide) return;
    alert.dataset.autohide = 'true';
    setTimeout(() => {
      alert.style.transition = 'opacity 0.5s ease';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 500);
    }, 3000);
  };

  document.querySelectorAll('.alert').forEach(hideAlert);

  const alertObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (node.nodeType === 1) {
          if (node.classList.contains('alert')) {
            hideAlert(node);
          } else {
            node.querySelectorAll('.alert').forEach(hideAlert);
          }
        }
      });
    });
  });
  alertObserver.observe(document.body, { childList: true, subtree: true });

  const pwIcon = (visible) => {
    if (visible) {
      return `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path fill="currentColor" d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7Zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>
        </svg>
      `;
    }
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path fill="currentColor" d="M2.1 3.5 3.5 2.1 21.9 20.5 20.5 21.9l-3.2-3.2c-1.6.9-3.4 1.3-5.3 1.3-7 0-10-7-10-7 1.1-2.1 2.7-3.9 4.7-5.2L2.1 3.5Zm7.2 7.2a3 3 0 0 0 4.2 4.2l-4.2-4.2Zm2.7-5.7c7 0 10 7 10 7-.9 1.7-2.1 3.2-3.6 4.3l-2-2A5 5 0 0 0 9.4 7.6L7.9 6.1c1.3-.7 2.7-1.1 4.1-1.1Z"/>
      </svg>
    `;
  };

  const initPasswordToggles = () => {
    const inputs = Array.from(document.querySelectorAll('input[type="password"]'));
    inputs.forEach((input) => {
      if (input.closest('.pw-wrap')) return;
      const wrap = document.createElement('div');
      wrap.className = 'pw-wrap';
      const parent = input.parentNode;
      if (!parent) return;
      parent.insertBefore(wrap, input);
      wrap.appendChild(input);

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pw-toggle';
      btn.setAttribute('aria-label', 'Passwort anzeigen');
      btn.setAttribute('aria-pressed', 'false');
      btn.innerHTML = pwIcon(false);
      btn.addEventListener('click', () => {
        const isVisible = input.type === 'text';
        input.type = isVisible ? 'password' : 'text';
        btn.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
        btn.setAttribute('aria-label', isVisible ? 'Passwort anzeigen' : 'Passwort verbergen');
        btn.innerHTML = pwIcon(!isVisible);
        input.focus();
      });
      wrap.appendChild(btn);
    });
  };

  initPasswordToggles();

  // @doc-section display-mode
  // @doc-purpose Erkennt die verfuegbare Anzeigebreite und markiert die Seite explizit als Mobil- oder Desktopmodus.
  const initDisplayMode = () => {
    const root = document.documentElement;
    const body = document.body;
    const mobileQuery = window.matchMedia ? window.matchMedia('(max-width: 720px)') : null;

    const applyMode = () => {
      const viewportWidth = window.visualViewport && window.visualViewport.width
        ? Math.round(window.visualViewport.width)
        : window.innerWidth;
      const mode = (mobileQuery && mobileQuery.matches) || viewportWidth <= 720 ? 'mobile' : 'desktop';
      const previousMode = root.getAttribute('data-display-mode') || '';

      root.setAttribute('data-display-mode', mode);
      body.setAttribute('data-display-mode', mode);
      body.classList.toggle('is-mobile-mode', mode === 'mobile');
      body.classList.toggle('is-desktop-mode', mode === 'desktop');
      window.appDisplayMode = mode;

      if (previousMode !== mode) {
        window.dispatchEvent(new CustomEvent('app:display-mode-change', {
          detail: { mode }
        }));
      }
    };

    applyMode();
    window.addEventListener('resize', applyMode, { passive: true });
    window.addEventListener('orientationchange', applyMode);
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', applyMode);
    }
    if (mobileQuery) {
      if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', applyMode);
      } else if (typeof mobileQuery.addListener === 'function') {
        mobileQuery.addListener(applyMode);
      }
    }
  };

  initDisplayMode();

  // @doc-section registration-autoscroll
  // @doc-purpose Scrollt nach dem Laden sanft bis knapp unter das DLRG-Logo, falls der eigentliche Seiteninhalt sonst noch verdeckt waere.
  const initHeaderAutoScroll = () => {
    if (window.location.hash) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const header = document.querySelector('.reg-header');
    const logo = header ? header.querySelector('.reg-logo') : null;
    if (!header || !logo) return;

    const container = header.closest('.container');
    const contentStart = (() => {
      if (!container) return null;
      const children = Array.from(container.children).filter((el) => el !== header && !el.hidden);
      return children.find((el) => {
        if (!(el instanceof HTMLElement)) return false;
        const rect = el.getBoundingClientRect();
        return rect.height > 0 || el.querySelector('*');
      }) || null;
    })();
    if (!contentStart) return;

    const cancelEvents = ['pointerdown', 'touchstart', 'wheel', 'keydown'];
    let rafId = 0;
    let cancelled = false;

    const cleanup = () => {
      if (rafId) {
        window.cancelAnimationFrame(rafId);
        rafId = 0;
      }
      cancelEvents.forEach((eventName) => {
        window.removeEventListener(eventName, cancelScroll, { capture: true });
      });
      try {
        if ('scrollRestoration' in window.history) {
          window.history.scrollRestoration = 'auto';
        }
      } catch {
      }
    };

    const cancelScroll = () => {
      cancelled = true;
      cleanup();
    };

    const run = () => {
      if (cancelled) return;
      if (window.scrollY > 4) return;

      const contentRect = contentStart.getBoundingClientRect();
      if (contentRect.top <= window.innerHeight - 24) return;

      const documentHeight = Math.max(
        document.body.scrollHeight,
        document.documentElement.scrollHeight
      );
      const maxScrollTop = Math.max(0, documentHeight - window.innerHeight);
      if (maxScrollTop <= 0) return;

      const logoRect = logo.getBoundingClientRect();
      const headerStyle = window.getComputedStyle(header);
      const extraOffset = Math.max(12, Math.round(parseFloat(headerStyle.paddingBottom || '0') / 2) || 0);
      const targetTop = Math.min(
        maxScrollTop,
        Math.max(0, Math.round(window.scrollY + logoRect.bottom + extraOffset))
      );
      if (targetTop <= 24) return;

      const startTop = window.scrollY;
      const distance = targetTop - startTop;
      if (distance <= 0) return;

      try {
        if ('scrollRestoration' in window.history) {
          window.history.scrollRestoration = 'manual';
        }
      } catch {
      }

      cancelEvents.forEach((eventName) => {
        window.addEventListener(eventName, cancelScroll, { passive: true, capture: true });
      });

      const duration = Math.min(1400, Math.max(650, distance * 1.5));
      const easeInOut = (progress) => (progress < 0.5
        ? 4 * progress * progress * progress
        : 1 - Math.pow(-2 * progress + 2, 3) / 2);
      let startTime = null;

      const step = (timestamp) => {
        if (cancelled) return;
        if (startTime === null) startTime = timestamp;
        const elapsed = timestamp - startTime;
        const progress = Math.min(1, elapsed / duration);
        const nextTop = startTop + distance * easeInOut(progress);
        window.scrollTo(0, nextTop);
        if (progress < 1) {
          rafId = window.requestAnimationFrame(step);
          return;
        }
        cleanup();
      };

      rafId = window.requestAnimationFrame(step);
    };

    window.addEventListener('load', () => {
      window.setTimeout(run, 180);
    }, { once: true });
  };

  initHeaderAutoScroll();

  // @doc-section team-selection
  // @doc-purpose Steuert die Umschaltung zwischen bestehender Teamauswahl und Eingabe eines neuen Teams.
  // @doc-ui-flow team-create-inline
  // @doc-ui-step 1 Nutzer waehlt im Team-Dropdown den Sonderwert fuer ein neues Team.
  // @doc-ui-step 2 Das Eingabefeld fuer den neuen Teamnamen wird sichtbar und verpflichtend.
  // @doc-ui-result Neue Teams koennen direkt im Formular angelegt werden.
  const teamSelect = document.getElementById('team_select');
  const newTeamWrapper = document.getElementById('new_team_wrapper');
  const newTeamInput = document.getElementById('new_team_input');

  const syncTeamUi = () => {
    if (!teamSelect || !newTeamWrapper || !newTeamInput) return;
    if (teamSelect.value === '__NEW__') {
      newTeamWrapper.style.display = 'block';
      newTeamInput.required = true;
      newTeamInput.setAttribute('aria-required', 'true');
      newTeamInput.focus();
    } else {
      newTeamWrapper.style.display = 'none';
      newTeamInput.required = false;
      newTeamInput.removeAttribute('aria-required');
      newTeamInput.value = '';
    }
  };

  if (teamSelect) {
    teamSelect.addEventListener('change', syncTeamUi);
    syncTeamUi();
  }


  // @doc-section modal-base
  // @doc-purpose Kapselt das generische Oeffnen und Schliessen von Modalen inklusive Fokusfuehrung.
  const modalOverlays = Array.from(document.querySelectorAll('.modal-overlay'));
  let activeModal = null;
  let lastFocusEl = null;

  const closeModal = (overlay) => {
    if (!overlay) return;
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    activeModal = null;
    const el = lastFocusEl;
    lastFocusEl = null;
    if (el && typeof el.focus === 'function') el.focus();
  };

  const openModal = (overlay) => {
    if (!overlay) return;
    lastFocusEl = document.activeElement;
    modalOverlays.forEach((o) => o.setAttribute('aria-hidden', 'true'));
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    activeModal = overlay;
    const panel = overlay.querySelector('.modal-panel');
    if (panel && typeof panel.focus === 'function') panel.focus();
  };

  if (modalOverlays.length) {
    document.querySelectorAll('[data-modal-open]').forEach((btn) => {
      const targetId = btn.getAttribute('data-modal-open');
      if (!targetId) return;
      const overlay = document.getElementById(targetId);
      if (!overlay) return;
      btn.addEventListener('click', () => openModal(overlay));
    });

    modalOverlays.forEach((overlay) => {
      overlay.querySelectorAll('[data-modal-close]').forEach((btn) => {
        btn.addEventListener('click', () => closeModal(overlay));
      });
      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeModal(overlay);
      });
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && activeModal) closeModal(activeModal);
    });
  }

  const participantEditOverlay = document.getElementById('modal_participant_edit');
  if (participantEditOverlay) {
    // @doc-section participant-edit
    // @doc-purpose Oeffnet die Bearbeitung eines Teilnehmers, befuellt das Formular und uebernimmt Aenderungen per AJAX.
    // @doc-ui-flow participant-edit
    // @doc-ui-step 1 Nutzer klickt in der Teilnehmerliste auf Bearbeiten.
    // @doc-ui-step 2 Das Modal wird mit den Datensaetzen des geklickten Teilnehmers vorbefuellt.
    // @doc-ui-step 3 Nach dem Speichern aktualisiert das Skript die sichtbare Tabellenzeile ohne Seitenreload.
    // @doc-ui-result Teilnehmerdaten bleiben direkt in der Liste synchron.
    const form = document.getElementById('participant_edit_form');
    const msg = document.getElementById('participant_edit_msg');
    const idInput = document.getElementById('participant_edit_id');
    const vornameInput = document.getElementById('participant_edit_vorname');
    const nachnameInput = document.getElementById('participant_edit_nachname');
    const volljaehrigSelect = document.getElementById('participant_edit_volljaehrig');
    const geschlechtSelect = document.getElementById('participant_edit_geschlecht');
    const teamSelect = document.getElementById('participant_edit_team_select');

    const setMsg = (html) => {
      if (!msg) return;
      msg.innerHTML = html || '';
    };

    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.btn-participant-edit');
      if (!btn) return;
      const id = btn.getAttribute('data-id') || '';
      if (!idInput || !vornameInput || !nachnameInput || !volljaehrigSelect || !geschlechtSelect || !teamSelect) return;
      idInput.value = id;
      vornameInput.value = btn.getAttribute('data-vorname') || '';
      nachnameInput.value = btn.getAttribute('data-nachname') || '';
      volljaehrigSelect.value = btn.getAttribute('data-volljaehrig') || '';
      geschlechtSelect.value = btn.getAttribute('data-geschlecht') || '';
      const teamIdRaw = btn.getAttribute('data-team-id') || '';
      const teamId = parseInt(teamIdRaw, 10);
      if (!Number.isNaN(teamId) && teamId > 0) {
        teamSelect.value = String(teamId);
      } else {
        teamSelect.value = '';
        const teamname = (btn.getAttribute('data-teamname') || '').trim();
        if (teamname !== '') {
          const opt = Array.from(teamSelect.options).find((o) => (o.textContent || '').trim().toLowerCase() === teamname.toLowerCase());
          if (opt) teamSelect.value = opt.value;
        }
      }
      setMsg('');
      openModal(participantEditOverlay);
      vornameInput.focus();
    });

    if (form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        setMsg('');
        try {
          const body = new URLSearchParams(new FormData(form));
          const res = await fetch('admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin',
          });
          const data = await res.json().catch(() => null);
          if (!res.ok || !data || !data.success) {
            const err = data && data.error ? String(data.error) : 'Speichern fehlgeschlagen.';
            setMsg(`<div class="alert alert-error no-autohide"><strong>Fehler:</strong> ${err}</div>`);
            return;
          }

          const updated = data.data || {};
          const id = String(updated.id ?? '');
          const row = id ? document.querySelector(`tr[data-participant-id="${id}"]`) : null;
          if (row) {
            const lastnameCell = getCellByColKey(row, 'participant_lastname');
            const firstnameCell = getCellByColKey(row, 'participant_firstname');
            const adultCell = getCellByColKey(row, 'participant_adult');
            const genderCell = getCellByColKey(row, 'participant_gender');
            const teamCell = getCellByColKey(row, 'participant_team');

            if (lastnameCell) lastnameCell.textContent = String(updated.nachname ?? '');
            if (firstnameCell) firstnameCell.textContent = String(updated.vorname ?? '');
            if (adultCell) adultCell.textContent = parseInt(updated.volljaehrig, 10) === 1 ? 'Ja' : 'Nein';
            if (genderCell) genderCell.textContent = String(updated.geschlecht ?? '');
            if (teamCell) {
              const team = updated.teamname ? String(updated.teamname) : '';
              teamCell.textContent = team;
            }

            const editBtn = row.querySelector('.btn-participant-edit');
            if (editBtn) {
              editBtn.setAttribute('data-vorname', String(updated.vorname ?? ''));
              editBtn.setAttribute('data-nachname', String(updated.nachname ?? ''));
              editBtn.setAttribute('data-volljaehrig', String(updated.volljaehrig ?? ''));
              editBtn.setAttribute('data-geschlecht', String(updated.geschlecht ?? ''));
              editBtn.setAttribute('data-team-id', String(updated.team_id ?? '0'));
              editBtn.setAttribute('data-teamname', String(updated.teamname ?? ''));
            }
          }

          closeModal(participantEditOverlay);
        } catch {
          setMsg('<div class="alert alert-error no-autohide"><strong>Fehler:</strong> Netzwerkfehler.</div>');
        } finally {
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    }
  }

  document.addEventListener('click', async (e) => {
    // @doc-section participant-delete
    // @doc-purpose Loescht einen Teilnehmer nach Rueckfrage und aktualisiert die Liste direkt im Browser.
    const btn = e.target.closest('.btn-participant-delete');
    if (!btn) return;

    const id = btn.getAttribute('data-id') || '';
    const name = btn.getAttribute('data-name') || '';
    if (!id) return;

    const label = name ? `${name} (ID ${id})` : `ID ${id}`;
    if (!confirm(`Teilnehmer ${label} wirklich löschen? Das kann nicht rückgängig gemacht werden.`)) return;

    const showMsg = (type, text) => {
      const panel = document.getElementById('participants_list');
      if (!panel) {
        alert(text);
        return;
      }
      const box = document.createElement('div');
      box.className = `alert ${type === 'success' ? 'alert-success' : 'alert-error'}`;
      const strong = document.createElement('strong');
      strong.textContent = type === 'success' ? 'Erfolg:' : 'Fehler:';
      box.appendChild(strong);
      box.appendChild(document.createTextNode(` ${text}`));
      const h2 = panel.querySelector('h2');
      if (h2) {
        h2.insertAdjacentElement('afterend', box);
      } else {
        panel.insertBefore(box, panel.firstChild);
      }
    };

    btn.disabled = true;
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const body = new URLSearchParams();
      body.set('ajax_action', 'delete_participant');
      body.set('id', String(id));
      body.set('csrf_token', csrfToken);

      const res = await fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString(),
        credentials: 'same-origin',
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data || !data.success) {
        const err = data && data.error ? String(data.error) : 'Teilnehmer konnte nicht gelöscht werden.';
        showMsg('error', err);
        return;
      }

      const row = btn.closest('tr[data-participant-id]');
      if (row) row.remove();
      showMsg('success', `Teilnehmer ${label} wurde gelöscht.`);
    } catch {
      showMsg('error', 'Netzwerkfehler.');
    } finally {
      btn.disabled = false;
    }
  });

  const globalTableSearchInput = document.getElementById('table_search');
  const tables = Array.from(document.querySelectorAll('table.data-table, table.dash2-table'))
    .filter((table) => table.dataset.tableManaged !== 'external');

  const safeParseJson = (raw) => {
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  };

  // @doc-section table-state-storage
  // @doc-purpose Verwaltet den gespeicherten Tabellenzustand fuer Sichtbarkeit und stabile Identifikation pro Tabelle.
  // @doc-ui-flow table-state-storage
  // @doc-ui-step 1 Fuer jede Tabelle wird aus Pfad, ID und Versionskennung ein eindeutiger Storage-Key erzeugt.
  // @doc-ui-step 2 Im Browser wird nur noch gespeichert, welche Spalten ausgeblendet sind; Breitenanpassungen werden nicht mehr persistiert.
  // @doc-ui-result Tabellen bleiben zwischen Seitenaufrufen konsistent, ohne veraltete Resizing-Daten zu uebernehmen.
  const getTableStorageKey = (table, tableIndex) => {
    const idPart = table && table.id ? String(table.id) : `idx-${tableIndex}`;
    const pathPart = window.location && window.location.pathname ? String(window.location.pathname) : '';
    const versionPart = table && table.dataset && table.dataset.tableVersion ? String(table.dataset.tableVersion) : 'v1';
    return `table_state:${pathPart}:${idPart}:${versionPart}`;
  };

  const loadTableState = (storageKey) => {
    try {
      const raw = window.localStorage ? window.localStorage.getItem(storageKey) : null;
      const parsed = safeParseJson(raw);
      const hiddenColumns = parsed && typeof parsed === 'object' && parsed.hiddenColumns && typeof parsed.hiddenColumns === 'object' ? parsed.hiddenColumns : {};
      return { hiddenColumns };
    } catch {
      return { hiddenColumns: {} };
    }
  };

  const saveTableState = (storageKey, state) => {
    try {
      if (!window.localStorage) return;
      const hiddenColumns = state && typeof state === 'object' && state.hiddenColumns && typeof state.hiddenColumns === 'object' ? state.hiddenColumns : {};
      window.localStorage.setItem(storageKey, JSON.stringify({ hiddenColumns }));
    } catch {
    }
  };

  const slugify = (text) => {
    return String(text || '')
      .trim()
      .toLowerCase()
      .replace(/\s+/g, '-')
      .replace(/[^a-z0-9_-]/g, '');
  };

  const ensureColgroup = (table, count) => {
    if (!table) return null;
    const existing = table.querySelector(':scope > colgroup');
    const colgroup = existing || document.createElement('colgroup');
    if (!existing) {
      table.insertBefore(colgroup, table.firstChild);
    }
    while (colgroup.children.length < count) {
      colgroup.appendChild(document.createElement('col'));
    }
    while (colgroup.children.length > count) {
      colgroup.removeChild(colgroup.lastElementChild);
    }
    return colgroup;
  };

  const parseSortValue = (text) => {
    const raw = String(text || '').trim();
    const dateMatch = /^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2}))?$/.exec(raw);
    if (dateMatch) {
      const [, d, m, y, h, min] = dateMatch;
      return new Date(y, m - 1, d, h || 0, min || 0).getTime();
    }
    if (/^-?\d+(?:[.,]\d+)?$/.test(raw)) {
      return parseFloat(raw.replace(',', '.'));
    }
    return raw.toLowerCase();
  };

  const getColumnIndexByKey = (table, colKey) => {
    if (!table || !colKey || !table.tHead || !table.tHead.rows || !table.tHead.rows.length) return -1;
    const headerRow = table.tHead.rows[0];
    return Array.from(headerRow.children).findIndex((cell) => cell && cell.dataset && cell.dataset.colKey === colKey);
  };

  const getCellByColKey = (row, colKey) => {
    if (!row || !colKey) return null;
    const table = row.closest('table');
    if (!table) return null;
    const index = getColumnIndexByKey(table, colKey);
    return index >= 0 && row.children[index] ? row.children[index] : null;
  };

  const lockedTableColumnKeys = new Set(['participant_actions']);

  const isLockedTableColumn = (key) => lockedTableColumnKeys.has(String(key || ''));

  const enhanceTable = (table, tableIndex) => {
    // @doc-section data-table-enhancement
    // @doc-purpose Erweitert Tabellen um Filter, Sortierung, Spaltenauswahl und Drag-and-drop.
    // @doc-side-effects Liest und schreibt Tabellenzustand in LocalStorage und veraendert DOM-Struktur.
    const thead = table.tHead;
    const tbody = table.tBodies && table.tBodies.length ? table.tBodies[0] : null;
    if (!thead || !tbody) return;

    const headerRow = thead.rows && thead.rows.length ? thead.rows[0] : null;
    if (!headerRow) return;

    const headers = Array.from(headerRow.querySelectorAll('th'));
    if (!headers.length) return;

    const getCurrentColIndex = (headerEl) => Array.from(headerRow.children).indexOf(headerEl);
    const storageKey = getTableStorageKey(table, tableIndex);
    const state = loadTableState(storageKey);

    const colgroup = ensureColgroup(table, headers.length);

    headers.forEach((th, i) => {
      const key = th.dataset.colKey || `t${tableIndex}_${i}_${slugify(th.textContent) || 'col'}`;
      th.dataset.colKey = key;
      if (!th.dataset.colLabel) {
        th.dataset.colLabel = String(th.textContent || '').trim();
      }
      if (colgroup && colgroup.children[i]) colgroup.children[i].dataset.colKey = key;
      if (isLockedTableColumn(key)) {
        if (state.hiddenColumns && Object.prototype.hasOwnProperty.call(state.hiddenColumns, key)) {
          delete state.hiddenColumns[key];
        }
      }
    });

    const applyFilter = () => {
      const localQ = (filterInput ? filterInput.value : '') || '';
      const globalQ = (globalTableSearchInput ? globalTableSearchInput.value : '') || '';
      const q = `${localQ} ${globalQ}`.toLowerCase().trim();
      const rows = Array.from(tbody.querySelectorAll('tr'));
      if (!q) {
        rows.forEach((r) => (r.style.display = ''));
        return;
      }
      rows.forEach((r) => {
        const text = (r.textContent || '').toLowerCase();
        r.style.display = text.includes(q) ? '' : 'none';
      });
    };

    let filterInput = null;
    const tableWrap = table.closest('.table-wrap');
    const tableToolsParent = tableWrap ? tableWrap.parentElement : null;
    let tools = null;
    if (tableToolsParent) {
      tools = tableToolsParent.querySelector(`.table-tools[data-table-tools="${tableIndex}"]`);
      if (!tools) {
        tools = document.createElement('div');
        tools.className = 'table-tools';
        tools.setAttribute('data-table-tools', String(tableIndex));
        tableToolsParent.insertBefore(tools, tableWrap);
      }
    }

    if (tools && !globalTableSearchInput) {
      filterInput = tools.querySelector(`input[data-table-filter="${tableIndex}"]`);
      if (!filterInput) {
        const filterLabel = document.createElement('label');
        filterLabel.className = 'table-tool-field';
        filterLabel.setAttribute('data-table-filter-wrap', String(tableIndex));

        const filterLabelText = document.createElement('span');
        filterLabelText.className = 'muted';
        filterLabelText.textContent = 'Filter';

        filterInput = document.createElement('input');
        filterInput.type = 'text';
        filterInput.placeholder = 'Filtern…';
        filterInput.setAttribute('data-table-filter', String(tableIndex));

        filterLabel.appendChild(filterLabelText);
        filterLabel.appendChild(filterInput);
        tools.appendChild(filterLabel);
      }
    }

    if (filterInput) {
      filterInput.addEventListener('input', applyFilter);
    }
    if (globalTableSearchInput) {
      globalTableSearchInput.addEventListener('input', () => {
        const section = table.closest('.admin-section');
        if (section && section.hidden) return;
        applyFilter();
      });
    }

    headers.forEach((header) => {
      header.classList.add('sortable');
      header.addEventListener('click', () => {
        const isAscending = header.classList.contains('asc');
        const direction = isAscending ? -1 : 1;
        headers.forEach((th) => th.classList.remove('asc', 'desc'));
        header.classList.add(isAscending ? 'desc' : 'asc');

        const rowsArray = Array.from(tbody.querySelectorAll('tr'));
        rowsArray.sort((a, b) => {
          const currentIndex = getCurrentColIndex(header);
          const aText = a.children[currentIndex] ? a.children[currentIndex].textContent : '';
          const bText = b.children[currentIndex] ? b.children[currentIndex].textContent : '';
          const aVal = parseSortValue(aText);
          const bVal = parseSortValue(bText);

          if (typeof aVal === 'string' && typeof bVal === 'string') {
            return aVal.localeCompare(bVal, 'de', { numeric: true }) * direction;
          }
          if (aVal < bVal) return -1 * direction;
          if (aVal > bVal) return 1 * direction;
          return 0;
        });
        rowsArray.forEach((row) => tbody.appendChild(row));
        applyFilter();
      });
    });

    const applyColumnVisibility = () => {
      headers.forEach((header) => {
        const key = header.dataset.colKey || '';
        const hidden = isLockedTableColumn(key) ? false : Boolean(key && state.hiddenColumns && state.hiddenColumns[key]);
        const currentIndex = getCurrentColIndex(header);
        const colEl = colgroup && currentIndex >= 0 ? colgroup.children[currentIndex] : null;

        header.style.display = hidden ? 'none' : '';
        if (colEl) colEl.style.display = hidden ? 'none' : '';

        Array.from(tbody.querySelectorAll('tr')).forEach((row) => {
          if (currentIndex >= 0 && row.children[currentIndex]) {
            row.children[currentIndex].style.display = hidden ? 'none' : '';
          }
        });
      });
    };

    if (tools && !tools.querySelector(`details[data-table-column-picker="${tableIndex}"]`)) {
      const picker = document.createElement('details');
      picker.className = 'table-column-picker';
      picker.setAttribute('data-table-column-picker', String(tableIndex));

      const summary = document.createElement('summary');
      summary.textContent = 'Spalten';
      picker.appendChild(summary);

      const options = document.createElement('div');
      options.className = 'table-column-options';

      headers.forEach((header) => {
        const key = header.dataset.colKey || '';
        if (isLockedTableColumn(key)) return;
        const optionLabel = document.createElement('label');
        optionLabel.className = 'table-column-option';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = !(key && state.hiddenColumns && state.hiddenColumns[key]);
        checkbox.setAttribute('data-col-toggle', key);

        checkbox.addEventListener('change', () => {
          if (!key) return;
          state.hiddenColumns = state.hiddenColumns || {};
          if (checkbox.checked) {
            delete state.hiddenColumns[key];
          } else {
            state.hiddenColumns[key] = true;
          }
          saveTableState(storageKey, state);
          applyColumnVisibility();
        });

        const optionText = document.createElement('span');
        optionText.textContent = header.dataset.colLabel || String(header.textContent || '').trim() || 'Spalte';

        optionLabel.appendChild(checkbox);
        optionLabel.appendChild(optionText);
        options.appendChild(optionLabel);
      });

      picker.appendChild(options);
      tools.appendChild(picker);
    }

    let draggedIndex = -1;
    headers.forEach((th) => {
      th.setAttribute('draggable', 'true');
      th.addEventListener('dragstart', (e) => {
        draggedIndex = getCurrentColIndex(th);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
      });
      th.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
      });
      th.addEventListener('drop', (e) => {
        e.preventDefault();
        const targetIndex = getCurrentColIndex(th);
        if (draggedIndex === -1 || draggedIndex === targetIndex) return;

        const sourceIndex = draggedIndex;
        const sourceHeader = headerRow.children[sourceIndex];
        if (!sourceHeader) return;

        if (sourceIndex < targetIndex) {
          th.after(sourceHeader);
        } else {
          th.before(sourceHeader);
        }

        if (colgroup) {
          const sourceCol = colgroup.children[sourceIndex];
          const targetCol = colgroup.children[targetIndex];
          if (sourceCol && targetCol) {
            if (sourceIndex < targetIndex) {
              targetCol.after(sourceCol);
            } else {
              targetCol.before(sourceCol);
            }
          }
        }

        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.forEach((row) => {
          const sourceCell = row.children[sourceIndex];
          const targetCell = row.children[targetIndex];
          if (!sourceCell || !targetCell) return;
          if (sourceIndex < targetIndex) {
            targetCell.after(sourceCell);
          } else {
            targetCell.before(sourceCell);
          }
        });
      });
    });

    applyColumnVisibility();
    applyFilter();
  };

  tables.forEach((t, i) => enhanceTable(t, i));

  const adminNavButtons = Array.from(document.querySelectorAll('.admin-nav-btn[data-admin-target]'));
  const adminSections = Array.from(document.querySelectorAll('.admin-section[id]'));
  const adminAnchorGroup = document.getElementById('admin_anchor_group');
  const adminAnchorNav = document.getElementById('admin_anchor_nav');
  const adminAnchorEmpty = document.getElementById('admin_anchor_empty');
  const adminAnchorCopy = document.getElementById('admin_anchor_copy');

  if (adminNavButtons.length > 0 && adminSections.length > 0) {
    // @doc-section admin-navigation
    // @doc-purpose Schaltet zwischen Admin-Bereichen um, spiegelt den aktiven Abschnitt im URL-Hash und fuellt die Sidebar-Sprungmarken.
    const sectionsById = new Map(adminSections.map((el) => [el.id, el]));
    let activeSectionId = '';
    let activeAnchorButtons = [];
    let activeAnchorTargets = [];
    let anchorScrollTicking = false;

    const getSectionTitle = (sectionEl) => {
      if (!sectionEl) return '';
      const configuredTitle = sectionEl.getAttribute('data-admin-section-title');
      if (configuredTitle) return configuredTitle;
      const heading = sectionEl.querySelector('h1, h2, h3');
      return heading ? String(heading.textContent || '').trim() : '';
    };

    const setActiveAnchor = (anchorId) => {
      activeAnchorButtons.forEach((btn) => {
        const isActive = btn.getAttribute('data-admin-anchor') === anchorId;
        btn.classList.toggle('is-active-anchor', isActive);
      });
    };

    const updateActiveAnchorFromScroll = () => {
      if (!activeAnchorTargets.length) {
        return;
      }

      let current = activeAnchorTargets[0];
      activeAnchorTargets.forEach((target) => {
        const rect = target.getBoundingClientRect();
        if (rect.top <= 160) {
          current = target;
        }
      });

      if (current && current.id) {
        setActiveAnchor(current.id);
      }
    };

    const requestAnchorRefresh = () => {
      if (anchorScrollTicking) {
        return;
      }
      anchorScrollTicking = true;
      window.requestAnimationFrame(() => {
        anchorScrollTicking = false;
        updateActiveAnchorFromScroll();
      });
    };

    const syncAnchorNav = (sectionEl) => {
      activeAnchorButtons = [];
      activeAnchorTargets = [];

      if (!adminAnchorGroup || !adminAnchorNav || !adminAnchorEmpty || !sectionEl) {
        return;
      }

      const sectionTitle = getSectionTitle(sectionEl);
      if (adminAnchorCopy) {
        adminAnchorCopy.textContent = sectionTitle
          ? `Schnelle Sprünge innerhalb von "${sectionTitle}".`
          : 'Schnelle Sprünge zu relevanten Abschnitten innerhalb der geöffneten Unterseite.';
      }

      adminAnchorNav.replaceChildren();

      const anchors = Array.from(sectionEl.querySelectorAll('[data-admin-anchor-label][id]'))
        .filter((el) => !el.hidden && el.id !== sectionEl.id);

      adminAnchorTargets = anchors;

      if (anchors.length === 0) {
        adminAnchorEmpty.hidden = false;
        return;
      }

      adminAnchorEmpty.hidden = true;
      const fragment = document.createDocumentFragment();

      anchors.forEach((anchorEl) => {
        const label = String(anchorEl.getAttribute('data-admin-anchor-label') || '').trim();
        if (!label) {
          return;
        }

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-secondary admin-anchor-btn';
        btn.setAttribute('data-admin-anchor', anchorEl.id);
        btn.textContent = label;
        btn.addEventListener('click', () => {
          anchorEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
          setActiveAnchor(anchorEl.id);
        });
        fragment.appendChild(btn);
        activeAnchorButtons.push(btn);
      });

      adminAnchorNav.appendChild(fragment);
      updateActiveAnchorFromScroll();
    };

    const setActiveSection = (targetId, { updateHash, scrollToSection } = { updateHash: true, scrollToSection: true }) => {
      const id = sectionsById.has(targetId) ? targetId : null;
      if (!id) return;

      adminSections.forEach((el) => {
        el.hidden = el.id !== id;
      });

      adminNavButtons.forEach((btn) => {
        const isActive = btn.getAttribute('data-admin-target') === id;
        btn.classList.toggle('btn-secondary', !isActive);
      });

      if (updateHash) {
        try {
          history.replaceState(null, '', `#${id}`);
        } catch {
          location.hash = `#${id}`;
        }
      }

      if (globalTableSearchInput) {
        globalTableSearchInput.dispatchEvent(new Event('input', { bubbles: true }));
      }

      activeSectionId = id;
      const activeSection = sectionsById.get(id) || null;
      syncAnchorNav(activeSection);

      if (scrollToSection && activeSection) {
        activeSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    };

    adminNavButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const target = btn.getAttribute('data-admin-target');
        if (!target) return;
        setActiveSection(target);
      });
    });

    const initialHash = (location.hash || '').replace(/^#/, '');
    if (initialHash && sectionsById.has(initialHash)) {
      setActiveSection(initialHash, { updateHash: false, scrollToSection: false });
    } else {
      const firstTarget = adminNavButtons[0]?.getAttribute('data-admin-target');
      if (firstTarget) setActiveSection(firstTarget, { updateHash: false, scrollToSection: false });
    }

    window.addEventListener('hashchange', () => {
      const next = (location.hash || '').replace(/^#/, '');
      if (next && sectionsById.has(next) && next !== activeSectionId) {
        setActiveSection(next, { updateHash: false, scrollToSection: true });
      }
    });

    window.addEventListener('scroll', requestAnchorRefresh, { passive: true });
    window.addEventListener('resize', requestAnchorRefresh);
  }

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const teamManageOverlay = document.getElementById('modal_team_manage');
  const teamManageNameEl = document.getElementById('team_manage_name');
  const teamManageMsg = document.getElementById('team_manage_msg');
  const teamMembersList = document.getElementById('team_members_list');
  const teamUnassignedSelect = document.getElementById('team_unassigned_select');
  const teamUnassignedAddBtn = document.getElementById('team_unassigned_add');
  const teamSearchInput = document.getElementById('team_member_search');
  const teamSearchList = document.getElementById('team_search_list');
  const teamManageRenameBtn = document.getElementById('team_manage_rename');
  const teamManageDeleteBtn = document.getElementById('team_manage_delete');

  if (
    teamManageOverlay &&
    teamManageNameEl &&
    teamMembersList &&
    teamSearchInput &&
    teamSearchList &&
    teamUnassignedSelect &&
    teamUnassignedAddBtn &&
    teamManageRenameBtn &&
    teamManageDeleteBtn
  ) {
    // @doc-section team-management
    // @doc-purpose Verwaltet Teammitglieder in einem Modal und verbindet Suche, Zuordnung und Entfernen per AJAX.
    // @doc-ui-flow team-management
    // @doc-ui-step 1 Nutzer oeffnet Team verwalten an einer Team-Zeile.
    // @doc-ui-step 2 Das Modal laedt aktuelle Mitglieder und unzugeordnete Teilnehmer.
    // @doc-ui-step 3 Suche und Buttons aendern die Teamzuordnung ohne Vollreload.
    // @doc-ui-result Teamlisten bleiben waehrend der Bearbeitung aktuell sichtbar.
    let activeTeam = '';
    let searchTimer = null;
    let lastSearch = '';

    const escapeHtml = (str) => {
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    };

    const setMsg = (html) => {
      if (!teamManageMsg) return;
      teamManageMsg.innerHTML = html || '';
    };

    const setUnassignedLoading = () => {
      teamUnassignedSelect.disabled = true;
      teamUnassignedSelect.innerHTML = '<option value="" selected>— Laden… —</option>';
      teamUnassignedAddBtn.disabled = true;
    };

    const renderUnassigned = (participants) => {
      if (!Array.isArray(participants) || participants.length === 0) {
        teamUnassignedSelect.disabled = true;
        teamUnassignedSelect.innerHTML = '<option value="" selected>— Keine Teilnehmer ohne Team —</option>';
        teamUnassignedAddBtn.disabled = true;
        return;
      }

      const options = [
        `<option value="" selected disabled hidden>— auswählen (${participants.length}) —</option>`,
      ];
      participants.forEach((p) => {
        const name = `${p.nachname || ''} ${p.vorname || ''}`.trim();
        const code = p.code ? ` (${p.code})` : '';
        options.push(`<option value="${escapeHtml(p.id)}">${escapeHtml(name || '—')}${escapeHtml(code)}</option>`);
      });
      teamUnassignedSelect.innerHTML = options.join('');
      teamUnassignedSelect.disabled = false;
      teamUnassignedAddBtn.disabled = true;
    };

    const renderMembers = (members) => {
      if (!Array.isArray(members) || members.length === 0) {
        teamMembersList.innerHTML = '—';
        return;
      }
      teamMembersList.innerHTML = members
        .map((m) => {
          const name = `${m.nachname || ''} ${m.vorname || ''}`.trim();
          const code = m.code ? `(${m.code})` : '';
          return `
            <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:8px;">
              <div style="min-width:0;">
                <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(name || '—')}</div>
                <div class="hint" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(code)}</div>
              </div>
              <button type="button" class="btn btn-secondary team-member-remove" data-id="${escapeHtml(m.id)}" style="white-space:nowrap;">Entfernen</button>
            </div>
          `;
        })
        .join('');
    };

    const renderSearchResults = (results) => {
      if (!Array.isArray(results) || results.length === 0) {
        teamSearchList.innerHTML = '—';
        return;
      }
      teamSearchList.innerHTML = results
        .map((p) => {
          const name = `${p.nachname || ''} ${p.vorname || ''}`.trim();
          const team = p.teamname ? String(p.teamname) : '';
          const disabled = team === activeTeam ? 'disabled' : '';
          const badge = team === '' ? '' : `<span class="badge badge-yellow" style="margin-left:8px;">${escapeHtml(team)}</span>`;
          return `
            <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:8px;">
              <div style="min-width:0;">
                <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(name || '—')}${badge}</div>
                <div class="hint" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(p.code || '')}</div>
              </div>
              <button type="button" class="btn btn-primary team-member-add" data-id="${escapeHtml(p.id)}" ${disabled} style="white-space:nowrap;">Hinzufügen</button>
            </div>
          `;
        })
        .join('');
    };

    const loadMembers = async () => {
      if (!activeTeam) return;
      setMsg('');
      try {
        const res = await fetch(`admin.php?ajax_action=team_members&team=${encodeURIComponent(activeTeam)}`, { credentials: 'same-origin' });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.success) {
          setMsg(`<div class="alert alert-error no-autohide"><strong>Fehler:</strong> ${escapeHtml(data?.error || 'Teammitglieder konnten nicht geladen werden.')}</div>`);
          return;
        }
        renderMembers(data.data || []);
      } catch {
        setMsg('<div class="alert alert-error no-autohide"><strong>Fehler:</strong> Teammitglieder konnten nicht geladen werden.</div>');
      }
    };

    const loadUnassigned = async () => {
      setUnassignedLoading();
      try {
        const res = await fetch('admin.php?ajax_action=unassigned_participants', { credentials: 'same-origin' });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.success) {
          renderUnassigned([]);
          setMsg(`<div class="alert alert-error no-autohide"><strong>Fehler:</strong> ${escapeHtml(data?.error || 'Teilnehmer ohne Team konnten nicht geladen werden.')}</div>`);
          return;
        }
        renderUnassigned(data.data || []);
      } catch {
        renderUnassigned([]);
        setMsg('<div class="alert alert-error no-autohide"><strong>Fehler:</strong> Teilnehmer ohne Team konnten nicht geladen werden.</div>');
      }
    };

    const doSearch = async (q) => {
      const trimmed = String(q || '').trim();
      if (trimmed === '') {
        teamSearchList.innerHTML = '—';
        return;
      }
      lastSearch = trimmed;
      try {
        const res = await fetch(`admin.php?ajax_action=participant_search&q=${encodeURIComponent(trimmed)}`, { credentials: 'same-origin' });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.success) {
          renderSearchResults([]);
          return;
        }
        if (lastSearch !== trimmed) return;
        renderSearchResults(data.data || []);
      } catch {
        renderSearchResults([]);
      }
    };

    const setMemberTeam = async (participantId, teamname) => {
      setMsg('');
      const body = new URLSearchParams();
      body.set('ajax_action', 'team_set_member');
      body.set('csrf_token', csrfToken);
      body.set('participant_id', String(participantId));
      body.set('teamname', teamname || '');
      try {
        const res = await fetch('admin.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: body.toString(),
          credentials: 'same-origin',
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.success) {
          setMsg(`<div class="alert alert-error no-autohide"><strong>Fehler:</strong> ${escapeHtml(data?.error || 'Änderung konnte nicht gespeichert werden.')}</div>`);
          return;
        }
        await loadMembers();
        await loadUnassigned();
        await doSearch(teamSearchInput.value);
      } catch {
        setMsg('<div class="alert alert-error no-autohide"><strong>Fehler:</strong> Änderung konnte nicht gespeichert werden.</div>');
      }
    };

    const renameTeam = async (oldTeam) => {
      if (!oldTeam) return;
      const next = prompt('Neuer Teamname:', oldTeam);
      if (next === null) return;
      const newTeam = String(next).trim();
      if (newTeam === '' || newTeam === oldTeam) return;
      const body = new URLSearchParams();
      body.set('ajax_action', 'team_rename');
      body.set('csrf_token', csrfToken);
      body.set('old_teamname', oldTeam);
      body.set('new_teamname', newTeam);
      try {
        const res = await fetch('admin.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: body.toString(),
          credentials: 'same-origin',
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.success) {
          alert(data?.error || 'Team konnte nicht umbenannt werden.');
          return;
        }
        location.reload();
      } catch {
        alert('Team konnte nicht umbenannt werden.');
      }
    };

    const deleteTeam = async (team) => {
      if (!team) return;
      const ok = confirm(`Team "${team}" wirklich löschen?\n\nAlle Mitglieder werden aus dem Team entfernt.`);
      if (!ok) return;
      const body = new URLSearchParams();
      body.set('ajax_action', 'team_delete');
      body.set('csrf_token', csrfToken);
      body.set('teamname', team);
      try {
        const res = await fetch('admin.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: body.toString(),
          credentials: 'same-origin',
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.success) {
          alert(data?.error || 'Team konnte nicht gelöscht werden.');
          return;
        }
        location.reload();
      } catch {
        alert('Team konnte nicht gelöscht werden.');
      }
    };

    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.btn-team-manage');
      if (!btn) return;
      const team = btn.getAttribute('data-team') || '';
      if (!team) return;
      activeTeam = team;
      teamManageNameEl.textContent = team;
      setUnassignedLoading();
      teamSearchInput.value = '';
      teamSearchList.innerHTML = '—';
      teamMembersList.innerHTML = '—';
      setMsg('');
      openModal(teamManageOverlay);
      teamSearchInput.focus();
      loadMembers();
      loadUnassigned();
    });

    teamManageRenameBtn.addEventListener('click', async () => {
      await renameTeam(activeTeam);
    });

    teamManageDeleteBtn.addEventListener('click', async () => {
      await deleteTeam(activeTeam);
    });

    teamMembersList.addEventListener('click', (e) => {
      const btn = e.target.closest('.team-member-remove');
      if (!btn) return;
      const id = parseInt(btn.getAttribute('data-id') || '0', 10);
      if (!id) return;
      setMemberTeam(id, '');
    });

    teamUnassignedSelect.addEventListener('change', () => {
      const id = parseInt(teamUnassignedSelect.value || '0', 10);
      teamUnassignedAddBtn.disabled = !id;
    });

    teamUnassignedAddBtn.addEventListener('click', () => {
      const id = parseInt(teamUnassignedSelect.value || '0', 10);
      if (!id) return;
      setMemberTeam(id, activeTeam);
    });

    teamSearchList.addEventListener('click', (e) => {
      const btn = e.target.closest('.team-member-add');
      if (!btn) return;
      const id = parseInt(btn.getAttribute('data-id') || '0', 10);
      if (!id) return;
      setMemberTeam(id, activeTeam);
    });

    teamSearchInput.addEventListener('input', () => {
      if (searchTimer) clearTimeout(searchTimer);
      searchTimer = setTimeout(() => doSearch(teamSearchInput.value), 250);
    });
  }

  const auditTbody = document.getElementById('audit_tbody');
  const auditReloadBtn = document.getElementById('audit_reload');
  const auditMoreBtn = document.getElementById('audit_more');
  const auditClearTableBtn = document.getElementById('audit_clear_table');
  const auditClearAllBtn = document.getElementById('audit_clear_all');
  const auditStatus = document.getElementById('audit_status');
  const auditTableSel = document.getElementById('audit_table');
  const auditActionSel = document.getElementById('audit_action');
  const auditUserInput = document.getElementById('audit_user');
  const auditQInput = document.getElementById('audit_q');
  const auditFromInput = document.getElementById('audit_from');
  const auditToInput = document.getElementById('audit_to');
  const auditAuto = document.getElementById('audit_auto');

  const auditModalTitle = document.getElementById('modal_audit_title');
  const auditMeta = document.getElementById('audit_meta');
  const auditChanged = document.getElementById('audit_changed');
  const auditOld = document.getElementById('audit_old');
  const auditNew = document.getElementById('audit_new');
  const auditModalOpenBtn = document.getElementById('audit_modal_open');

  if (auditTbody && auditReloadBtn && auditMoreBtn) {
    // @doc-section audit-log
    // @doc-purpose Laedt, filtert, erweitert und loescht Audit-Eintraege in der Admin-Oberflaeche.
    // @doc-ui-flow audit-inspection
    // @doc-ui-step 1 Nutzer setzt Filter oder klickt auf Neu laden.
    // @doc-ui-step 2 Das Skript holt Auditdaten als JSON und rendert Tabellen- sowie Detailansicht.
    // @doc-ui-result Aenderungen an Datensaetzen koennen nachvollzogen und bei Bedarf bereinigt werden.
    const AUDIT_COLS = 8;
    let loading = false;
    let beforeId = null;
    let loadedCount = 0;
    let autoTimer = null;

    const setStatus = (text) => {
      if (!auditStatus) return;
      auditStatus.textContent = text;
    };

    const escapeHtml = (str) => {
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    };

    const parseJsonMaybe = (val) => {
      if (val === null || val === undefined) return null;
      const s = String(val).trim();
      if (s === '' || s === 'null') return null;
      try {
        return JSON.parse(s);
      } catch {
        return null;
      }
    };

    const prettyJson = (objOrString) => {
      const obj = typeof objOrString === 'string' ? parseJsonMaybe(objOrString) : objOrString;
      if (obj === null) return '—';
      try {
        return JSON.stringify(obj, null, 2);
      } catch {
        return String(objOrString ?? '—');
      }
    };

    const formatTime = (occurredAt) => {
      if (!occurredAt) return '—';
      const d = new Date(String(occurredAt).replace(' ', 'T'));
      if (Number.isNaN(d.getTime())) return String(occurredAt);
      return d.toLocaleString('de-DE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      });
    };

    const shallowDiffKeys = (oldObj, newObj) => {
      const o = oldObj && typeof oldObj === 'object' ? oldObj : null;
      const n = newObj && typeof newObj === 'object' ? newObj : null;
      if (!o && !n) return [];
      const keys = new Set([...(o ? Object.keys(o) : []), ...(n ? Object.keys(n) : [])]);
      const changed = [];
      for (const k of keys) {
        const ov = o ? o[k] : undefined;
        const nv = n ? n[k] : undefined;
        if (JSON.stringify(ov) !== JSON.stringify(nv)) changed.push(k);
      }
      changed.sort((a, b) => a.localeCompare(b, 'de'));
      return changed;
    };

    const valuePreview = (val, maxLen = 180) => {
      if (val === undefined) return { text: '—', title: '' };
      if (val === null) return { text: 'null', title: '' };
      if (typeof val === 'string') {
        const t = val;
        if (t.length <= maxLen) return { text: t === '' ? '""' : t, title: '' };
        return { text: t.slice(0, maxLen) + '…', title: t };
      }
      if (typeof val === 'number' || typeof val === 'boolean') {
        return { text: String(val), title: '' };
      }
      try {
        const t = JSON.stringify(val);
        if (t.length <= maxLen) return { text: t, title: '' };
        return { text: t.slice(0, maxLen) + '…', title: t };
      } catch {
        const t = String(val);
        if (t.length <= maxLen) return { text: t, title: '' };
        return { text: t.slice(0, maxLen) + '…', title: t };
      }
    };

    const renderDiffTable = (action, oldObj, newObj) => {
      const a = String(action || '').toUpperCase();
      const o = oldObj && typeof oldObj === 'object' ? oldObj : null;
      const n = newObj && typeof newObj === 'object' ? newObj : null;

      let keys = [];
      if (a === 'UPDATE') {
        keys = shallowDiffKeys(o, n);
      } else if (a === 'INSERT') {
        keys = n ? Object.keys(n) : [];
      } else if (a === 'DELETE') {
        keys = o ? Object.keys(o) : [];
      }
      keys = Array.from(new Set(keys)).sort((x, y) => String(x).localeCompare(String(y), 'de'));

      if (keys.length === 0) {
        return '<div>—</div>';
      }

      const headOld = a === 'INSERT' ? 'Alt' : 'Alt';
      const headNew = a === 'DELETE' ? 'Neu' : 'Neu';

      const rowsHtml = keys
        .map((k) => {
          const ov = o ? o[k] : undefined;
          const nv = n ? n[k] : undefined;
          const same = JSON.stringify(ov) === JSON.stringify(nv);
          const oldP = valuePreview(ov);
          const newP = valuePreview(nv);

          const oldTitle = oldP.title ? ` title="${escapeHtml(oldP.title)}"` : '';
          const newTitle = newP.title ? ` title="${escapeHtml(newP.title)}"` : '';

          const oldClass = `audit-diff-val audit-diff-old${a === 'INSERT' ? ' audit-diff-empty' : ''}${!same && a === 'UPDATE' ? ' audit-diff-changed' : ''}`;
          const newClass = `audit-diff-val audit-diff-new${a === 'DELETE' ? ' audit-diff-empty' : ''}${!same && a === 'UPDATE' ? ' audit-diff-changed' : ''}`;

          return `
            <tr>
              <td class="audit-diff-key"><strong>${escapeHtml(k)}</strong></td>
              <td class="${oldClass}"${oldTitle}>${escapeHtml(oldP.text)}</td>
              <td class="${newClass}"${newTitle}>${escapeHtml(newP.text)}</td>
            </tr>
          `;
        })
        .join('');

      return `
        <div class="table-wrap audit-diff-wrap">
          <table class="audit-diff">
            <thead>
              <tr>
                <th>Feld</th>
                <th>${headOld}</th>
                <th>${headNew}</th>
              </tr>
            </thead>
            <tbody>
              ${rowsHtml}
            </tbody>
          </table>
        </div>
      `;
    };

    const actionBadge = (action) => {
      const a = String(action || '').toUpperCase();
      if (a === 'INSERT') return '<span class="badge badge-green">INSERT</span>';
      if (a === 'UPDATE') return '<span class="badge badge-yellow">UPDATE</span>';
      if (a === 'DELETE') return '<span class="badge badge-red">DELETE</span>';
      return `<span class="badge">${escapeHtml(a || '—')}</span>`;
    };

    const buildParams = () => {
      const p = new URLSearchParams();
      p.set('ajax_action', 'audit_log');
      p.set('limit', '50');
      if (auditTableSel && auditTableSel.value) p.set('table', auditTableSel.value);
      if (auditActionSel && auditActionSel.value) p.set('action', auditActionSel.value);
      if (auditUserInput && auditUserInput.value.trim() !== '') p.set('user', auditUserInput.value.trim());
      if (auditQInput && auditQInput.value.trim() !== '') p.set('q', auditQInput.value.trim());
      if (auditFromInput && auditFromInput.value) p.set('from', auditFromInput.value);
      if (auditToInput && auditToInput.value) p.set('to', auditToInput.value);
      if (beforeId !== null) p.set('before_id', String(beforeId));
      return p;
    };

    const clearTable = () => {
      auditTbody.innerHTML = '';
      loadedCount = 0;
    };

    const renderPlaceholder = (text) => {
      auditTbody.innerHTML = `<tr><td colspan="${AUDIT_COLS}" class="muted">${escapeHtml(text)}</td></tr>`;
    };

    const renderRows = (rows, mode) => {
      const fragment = document.createDocumentFragment();
      rows.forEach((row) => {
        const oldObj = parseJsonMaybe(row.old_data);
        const newObj = parseJsonMaybe(row.new_data);
        const changed = row.action === 'UPDATE' ? shallowDiffKeys(oldObj, newObj) : [];
        const changedPreview =
          row.action === 'UPDATE'
            ? (changed.length ? `${changed.slice(0, 6).join(', ')}${changed.length > 6 ? ` (+${changed.length - 6})` : ''}` : '—')
            : row.action === 'INSERT'
              ? 'neu'
              : row.action === 'DELETE'
                ? 'gelöscht'
                : '—';

        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${escapeHtml(formatTime(row.occurred_at))}</td>
          <td>${escapeHtml(row.table_name || '—')}</td>
          <td>${actionBadge(row.action)}</td>
          <td>${escapeHtml(row.row_pk || '—')}</td>
          <td>${escapeHtml(row.username || '—')}${row.role ? ` <span class="muted">(${escapeHtml(row.role)})</span>` : ''}</td>
          <td>${escapeHtml(changedPreview)}</td>
          <td><button type="button" class="link-btn audit-details" data-audit-id="${escapeHtml(row.id)}">Details</button></td>
          <td><button type="button" class="link-btn audit-delete" data-audit-id="${escapeHtml(row.id)}">Löschen</button></td>
        `;
        tr.dataset.auditRow = JSON.stringify({
          id: row.id,
          occurred_at: row.occurred_at,
          table_name: row.table_name,
          action: row.action,
          row_pk: row.row_pk,
          username: row.username,
          role: row.role,
          old_data: row.old_data,
          new_data: row.new_data,
        });
        fragment.appendChild(tr);
      });

      if (mode === 'replace') {
        auditTbody.innerHTML = '';
      }
      auditTbody.appendChild(fragment);
      loadedCount += rows.length;
    };

    const deleteAuditEntry = async (id, tr, rowObj) => {
      if (!csrfToken) {
        setStatus('Sicherheitsprüfung fehlgeschlagen.');
        return;
      }
      const info = `${rowObj?.action || '—'} · ${rowObj?.table_name || '—'} · PK ${rowObj?.row_pk || '—'}`;
      if (!window.confirm(`Audit-Eintrag wirklich löschen?\n${info}`)) return;

      try {
        setStatus('Lösche …');
        const body = new URLSearchParams();
        body.set('ajax_action', 'audit_delete');
        body.set('id', String(id));
        body.set('csrf_token', csrfToken);

        const res = await fetch('admin.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
          body,
          credentials: 'same-origin',
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.success) {
          setStatus(data?.error || 'Eintrag konnte nicht gelöscht werden.');
          return;
        }

        tr.remove();
        loadedCount = Math.max(0, loadedCount - 1);
        if (loadedCount === 0) {
          renderPlaceholder('Keine Einträge gefunden.');
          setStatus('0 Einträge');
          return;
        }
        const refreshed = new Date().toLocaleTimeString('de-DE');
        setStatus(`${loadedCount} Einträge · aktualisiert ${refreshed}`);
      } catch {
        setStatus('Eintrag konnte nicht gelöscht werden (Netzwerkfehler).');
      }
    };

    const openDetails = (rowObj) => {
      if (!auditModalOpenBtn) return;
      if (auditModalTitle) {
        auditModalTitle.textContent = `${rowObj.action || '—'} · ${rowObj.table_name || '—'} · PK ${rowObj.row_pk || '—'}`;
      }

      const oldObj = parseJsonMaybe(rowObj.old_data);
      const newObj = parseJsonMaybe(rowObj.new_data);

      if (auditMeta) {
        const metaItems = [
          { k: 'Zeit', v: formatTime(rowObj.occurred_at) },
          { k: 'User', v: `${rowObj.username || '—'}${rowObj.role ? ` (${rowObj.role})` : ''}` },
          { k: 'Tabelle', v: rowObj.table_name || '—' },
          { k: 'PK', v: rowObj.row_pk || '—' },
        ];
        auditMeta.innerHTML = metaItems
          .map((it) => `<div><strong>${escapeHtml(it.k)}:</strong> ${escapeHtml(it.v)}</div>`)
          .join('');
      }

      if (auditChanged) {
        auditChanged.innerHTML = renderDiffTable(rowObj.action, oldObj, newObj);
      }

      if (auditOld) auditOld.textContent = prettyJson(rowObj.old_data);
      if (auditNew) auditNew.textContent = prettyJson(rowObj.new_data);

      auditModalOpenBtn.click();
    };

    const loadAudit = async ({ reset }) => {
      if (loading) return;
      loading = true;
      auditReloadBtn.disabled = true;
      auditMoreBtn.disabled = true;

      if (reset) {
        beforeId = null;
        clearTable();
        renderPlaceholder('Lade …');
      } else if (!beforeId) {
        loading = false;
        auditReloadBtn.disabled = false;
        auditMoreBtn.disabled = false;
        return;
      }

      try {
        setStatus('Lade …');
        const p = buildParams();
        const res = await fetch(`admin.php?${p.toString()}`, {
          headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        if (!data || !data.success) {
          renderPlaceholder(data && data.error ? data.error : 'Audit Log konnte nicht geladen werden.');
          setStatus('');
          return;
        }

        const rows = Array.isArray(data.rows) ? data.rows : [];
        if (reset) {
          auditTbody.innerHTML = '';
        }

        if (rows.length === 0 && loadedCount === 0) {
          renderPlaceholder('Keine Einträge gefunden.');
          setStatus('0 Einträge');
          return;
        }

        renderRows(rows, reset ? 'replace' : 'append');

        const lastRow = rows.length ? rows[rows.length - 1] : null;
        if (lastRow && lastRow.id) {
          beforeId = String(lastRow.id);
        }

        auditMoreBtn.disabled = !data.has_more;
        auditReloadBtn.disabled = false;
        const refreshed = data.server_time ? new Date(data.server_time).toLocaleTimeString('de-DE') : '';
        setStatus(`${loadedCount} Einträge · aktualisiert ${refreshed}`);
      } catch {
        renderPlaceholder('Audit Log konnte nicht geladen werden (Netzwerkfehler).');
        setStatus('');
      } finally {
        loading = false;
        auditReloadBtn.disabled = false;
        if (!auditMoreBtn.disabled) auditMoreBtn.disabled = false;
      }
    };

    const debounce = (fn, ms) => {
      let t = null;
      return (...args) => {
        if (t) window.clearTimeout(t);
        t = window.setTimeout(() => fn(...args), ms);
      };
    };

    auditTbody.addEventListener('click', (e) => {
      const delBtn = e.target.closest('.audit-delete');
      if (delBtn) {
        const tr = delBtn.closest('tr');
        if (!tr) return;
        const raw = tr.dataset.auditRow;
        if (!raw) return;
        try {
          const obj = JSON.parse(raw);
          const id = parseInt(String(obj.id || '0'), 10);
          if (!id) return;
          deleteAuditEntry(id, tr, obj);
        } catch {
        }
        return;
      }

      const btn = e.target.closest('.audit-details');
      if (!btn) return;
      const tr = btn.closest('tr');
      if (!tr) return;
      const raw = tr.dataset.auditRow;
      if (!raw) return;
      try {
        const obj = JSON.parse(raw);
        openDetails(obj);
      } catch {
      }
    });

    auditReloadBtn.addEventListener('click', () => loadAudit({ reset: true }));
    auditMoreBtn.addEventListener('click', () => loadAudit({ reset: false }));

    const updateClearButtons = () => {
      if (auditClearTableBtn) {
        auditClearTableBtn.disabled = !auditTableSel || auditTableSel.value === 'all';
      }
    };

    if (auditClearTableBtn) {
      auditClearTableBtn.addEventListener('click', async () => {
        if (!auditTableSel || auditTableSel.value === 'all') return;
        if (!csrfToken) {
          setStatus('Sicherheitsprüfung fehlgeschlagen.');
          return;
        }
        const tableName = auditTableSel.value;
        if (!window.confirm(`Audit-Logs für Tabelle "${tableName}" wirklich leeren?`)) return;
        try {
          setStatus('Lösche …');
          const body = new URLSearchParams();
          body.set('ajax_action', 'audit_clear_table');
          body.set('table_name', tableName);
          body.set('csrf_token', csrfToken);
          const res = await fetch('admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
            body,
            credentials: 'same-origin',
          });
          const data = await res.json().catch(() => null);
          if (!res.ok || !data || !data.success) {
            setStatus(data?.error || 'Logs konnten nicht gelöscht werden.');
            return;
          }
          await loadAudit({ reset: true });
        } catch {
          setStatus('Logs konnten nicht gelöscht werden (Netzwerkfehler).');
        }
      });
    }

    if (auditClearAllBtn) {
      auditClearAllBtn.addEventListener('click', async () => {
        if (!csrfToken) {
          setStatus('Sicherheitsprüfung fehlgeschlagen.');
          return;
        }
        if (!window.confirm('Wirklich alle Audit-Logs global löschen?')) return;
        try {
          setStatus('Lösche …');
          const body = new URLSearchParams();
          body.set('ajax_action', 'audit_clear_all');
          body.set('csrf_token', csrfToken);
          const res = await fetch('admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
            body,
            credentials: 'same-origin',
          });
          const data = await res.json().catch(() => null);
          if (!res.ok || !data || !data.success) {
            setStatus(data?.error || 'Logs konnten nicht gelöscht werden.');
            return;
          }
          await loadAudit({ reset: true });
        } catch {
          setStatus('Logs konnten nicht gelöscht werden (Netzwerkfehler).');
        }
      });
    }

    const onFilterChanged = debounce(() => loadAudit({ reset: true }), 250);
    [auditTableSel, auditActionSel, auditFromInput, auditToInput].forEach((el) => {
      if (!el) return;
      el.addEventListener('change', () => loadAudit({ reset: true }));
    });
    [auditUserInput, auditQInput].forEach((el) => {
      if (!el) return;
      el.addEventListener('input', onFilterChanged);
    });

    if (auditTableSel) {
      auditTableSel.addEventListener('change', updateClearButtons);
    }
    updateClearButtons();

    if (auditAuto) {
      const stopAuto = () => {
        if (autoTimer) window.clearInterval(autoTimer);
        autoTimer = null;
      };
      const startAuto = () => {
        stopAuto();
        autoTimer = window.setInterval(() => loadAudit({ reset: true }), 8000);
      };
      auditAuto.addEventListener('change', () => {
        if (auditAuto.checked) startAuto();
        else stopAuto();
      });
      window.addEventListener('beforeunload', stopAuto);
    }

    loadAudit({ reset: true });
  }
});
