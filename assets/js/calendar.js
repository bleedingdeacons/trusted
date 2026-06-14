/* global TrustedData */
(function () {
    'use strict';

    var cfg = window.TrustedData || {};
    var i18n = cfg.i18n || {};
    var root = document.getElementById('trusted-calendar');
    if (!root) {
        return;
    }

    var DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    // Remember the last-viewed week across reloads so the calendar reopens on the
    // same week the user navigated to, rather than snapping back to the current
    // week each time the page loads.
    var WEEK_STORAGE_KEY = 'trustedWeekStart';

    function isIsoDate(v) {
        return typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v);
    }

    function loadStoredWeek() {
        try {
            var stored = window.localStorage.getItem(WEEK_STORAGE_KEY);
            return isIsoDate(stored) ? stored : null;
        } catch (e) {
            return null; // storage may be unavailable (private mode, etc.)
        }
    }

    function saveWeek(isoDate) {
        try {
            window.localStorage.setItem(WEEK_STORAGE_KEY, isoDate);
        } catch (e) { /* ignore — persistence is best-effort */ }
    }

    var state = {
        weekStart: loadStoredWeek() || cfg.weekStart,
        members: null,   // cached member list
        templates: null, // cached template list
        bulk: null       // bulk-assign mode: { memberId: '', selected: { rotaId: true } }
    };

    // References to the live bulk bar controls, so selecting/ticking can update
    // the count and toggle the Assign button without a full re-render.
    var bulkCountLabel = null;
    var bulkAssignBtn = null;

    // --- REST helpers -------------------------------------------------------

    function api(path, options) {
        options = options || {};
        options.headers = Object.assign({
            'Content-Type': 'application/json',
            'X-WP-Nonce': cfg.nonce
        }, options.headers || {});
        if (options.body && typeof options.body !== 'string') {
            options.body = JSON.stringify(options.body);
        }
        return fetch(cfg.restRoot + path, options).then(function (res) {
            if (!res.ok) {
                return res.json().catch(function () { return {}; }).then(function (err) {
                    throw new Error(err.message || ('Request failed (' + res.status + ')'));
                });
            }
            return res.status === 204 ? null : res.json();
        });
    }

    // --- Date helpers -------------------------------------------------------

    function addDays(isoDate, days) {
        var d = new Date(isoDate + 'T00:00:00');
        d.setDate(d.getDate() + days);
        return d.toISOString().slice(0, 10);
    }

    function prettyDate(isoDate) {
        var d = new Date(isoDate + 'T00:00:00');
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    }

    // --- DOM helpers --------------------------------------------------------

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            if (k === 'class') { node.className = attrs[k]; }
            else if (k === 'text') { node.textContent = attrs[k]; }
            else if (k === 'html') { node.innerHTML = attrs[k]; }
            else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') {
                node.addEventListener(k.slice(2), attrs[k]);
            } else { node.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) {
            if (c == null) { return; }
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }

    function clear(node) {
        while (node.firstChild) { node.removeChild(node.firstChild); }
    }

    // --- Confirm tooltip (small ACF-style popup anchored to an element) -----

    var activePopup = null;

    function dismissPopup() {
        if (!activePopup) { return; }
        document.removeEventListener('mousedown', activePopup.outside, true);
        document.removeEventListener('keydown', activePopup.outside, true);
        if (activePopup.node.parentNode) { activePopup.node.parentNode.removeChild(activePopup.node); }
        activePopup = null;
    }

    // Show a confirm bubble next to anchorEl: "<message> <Confirm> <Cancel>".
    // Clicking the confirm link runs onConfirm; the cancel link, an outside
    // click, or Escape just dismisses it. Only one popup shows at a time.
    function showConfirm(anchorEl, message, confirmLabel, cancelLabel, onConfirm) {
        dismissPopup();

        var node = el('div', { class: 'trusted-tooltip top' }, [
            el('span', { text: message + ' ' }),
            el('a', {
                href: '#', class: 'trusted-tooltip-confirm', text: confirmLabel,
                onclick: function (e) { e.preventDefault(); dismissPopup(); if (onConfirm) { onConfirm(); } }
            }),
            document.createTextNode(' '),
            el('a', {
                href: '#', class: 'trusted-tooltip-cancel', text: cancelLabel,
                onclick: function (e) { e.preventDefault(); dismissPopup(); }
            })
        ]);

        node.style.visibility = 'hidden';
        document.body.appendChild(node);

        // Centre the bubble above the anchor, flipping below if there's no room.
        var rect = anchorEl.getBoundingClientRect();
        var w = node.offsetWidth, h = node.offsetHeight;
        var top = rect.top - h - 10;
        var left = Math.max(8, Math.min(rect.left + rect.width / 2 - w / 2, window.innerWidth - w - 8));
        if (top < 8) {
            node.classList.remove('top');
            node.classList.add('bottom');
            top = rect.bottom + 10;
        }
        node.style.top = top + 'px';
        node.style.left = left + 'px';
        node.style.visibility = '';

        function outside(e) {
            if (e.type === 'keydown' ? e.key === 'Escape' : !node.contains(e.target)) {
                dismissPopup();
            }
        }

        activePopup = { node: node, outside: outside };

        // Defer so the click that opened the popup doesn't immediately close it.
        window.setTimeout(function () {
            document.addEventListener('mousedown', outside, true);
            document.addEventListener('keydown', outside, true);
        }, 0);
    }

    // --- Data loading -------------------------------------------------------

    function loadMembers() {
        if (state.members) { return Promise.resolve(state.members); }
        return api('/members').then(function (list) {
            state.members = list || [];
            return state.members;
        });
    }

    function loadTemplates() {
        if (state.templates) { return Promise.resolve(state.templates); }
        return api('/templates').then(function (list) {
            state.templates = list || [];
            return state.templates;
        });
    }

    // Re-fetch everything in place — drops the cached members/templates so they
    // reload too, then re-renders the current week. No full page reload, and the
    // viewed week is preserved (render() always re-fetches state.weekStart).
    function refresh() {
        state.members = null;
        state.templates = null;
        render();
    }

    // --- Rendering ----------------------------------------------------------

    function render() {
        saveWeek(state.weekStart); // remember the week for the next reload
        closeOpenPicker = null; // the DOM is about to be rebuilt
        clear(root);
        root.appendChild(el('p', { class: 'trusted-loading', text: 'Loading rota…' }));

        // Load week data, members and templates BEFORE building the toolbar, so
        // the template dropdown is populated when it is first rendered.
        Promise.all([api('/week/' + state.weekStart), loadMembers(), loadTemplates()])
            .then(function (results) {
                clear(root);
                root.appendChild(buildToolbar(results[0]));
                if (state.bulk) { root.appendChild(buildBulkBar()); }
                var grid = el('div', { class: 'trusted-grid' });
                root.appendChild(grid);
                renderGrid(grid, results[0]);
                if (state.bulk) { updateBulkBar(); }
            })
            .catch(function (e) {
                clear(root);
                root.appendChild(el('div', { class: 'notice notice-error', html: '<p>' + e.message + '</p>' }));
            });
    }

    // True when any day in the week has at least one shift slot.
    function weekHasSlots(week) {
        return (week.days || []).some(function (d) { return (d.slots || []).length > 0; });
    }

    // True when any slot in the week has a member assigned.
    function weekHasAssignments(week) {
        return (week.days || []).some(function (d) {
            return (d.slots || []).some(function (s) { return (s.assignments || []).length > 0; });
        });
    }

    function buildToolbar(week) {
        var label = state.weekStart + ' – ' + addDays(state.weekStart, 6);

        var templateSelect = el('select', { class: 'trusted-template-select' });
        templateSelect.appendChild(el('option', { value: '', text: i18n.applyTemplate || 'Apply template' }));
        (state.templates || []).forEach(function (t) {
            templateSelect.appendChild(el('option', { value: t.id, text: t.title }));
        });
        if (!state.templates || !state.templates.length) {
            templateSelect.appendChild(el('option', { value: '', disabled: 'disabled', text: i18n.noTemplates || 'No templates yet' }));
        }

        var replaceBox = el('input', { type: 'checkbox', id: 'trusted-replace' });
        var applyBtn = el('button', {
            class: 'button button-primary',
            text: i18n.applyTemplate || 'Apply template',
            onclick: function () {
                var id = parseInt(templateSelect.value, 10);
                if (!id) { return; }
                applyBtn.disabled = true;
                api('/apply-template', {
                    method: 'POST',
                    body: { template_id: id, week_start: state.weekStart, replace: replaceBox.checked }
                }).then(function () {
                    applyBtn.disabled = false;
                    render();
                }).catch(function (e) {
                    applyBtn.disabled = false;
                    window.alert(e.message);
                });
            }
        });

        var bulkBtn = el('button', {
            class: 'button trusted-bulk-start',
            text: i18n.bulkAssign || 'Assign member to shifts',
            onclick: function () {
                state.bulk = { memberId: '', selected: {} };
                render();
            }
        });

        var toolbar;

        var saveTemplateBtn = el('button', {
            class: 'button trusted-save-template-start',
            text: i18n.saveAsTemplate || 'Save week as template',
            onclick: function () { openSaveTemplate(toolbar); }
        });

        // Two complementary week-level actions, shown only outside bulk mode and
        // never together: "Clear week" deletes the shifts while the week is
        // unstarted (no assignments); "Delete week's assignments" frees every
        // slot but keeps the shifts, and so only appears once someone is assigned.
        var clearBtn = null;
        var clearAssignmentsBtn = null;

        if (!state.bulk && weekHasSlots(week) && !weekHasAssignments(week)) {
            clearBtn = el('button', {
                class: 'button trusted-clear-week',
                text: i18n.clearWeek || 'Clear week',
                onclick: function () {
                    if (!window.confirm(i18n.confirmClearWeek || 'Delete all shifts for this week?')) { return; }
                    clearBtn.disabled = true;
                    api('/week/' + state.weekStart, { method: 'DELETE' })
                        .then(function () { render(); })
                        .catch(function (e) { clearBtn.disabled = false; window.alert(e.message); });
                }
            });
        }

        if (!state.bulk && weekHasAssignments(week)) {
            clearAssignmentsBtn = el('button', {
                class: 'button trusted-clear-assignments',
                text: i18n.clearAssignments || 'Delete week\'s assignments',
                onclick: function () {
                    if (!window.confirm(i18n.confirmClearAssignments || 'Remove every member assignment for this week?')) { return; }
                    clearAssignmentsBtn.disabled = true;
                    api('/week/' + state.weekStart + '/assignments', { method: 'DELETE' })
                        .then(function () { render(); })
                        .catch(function (e) { clearAssignmentsBtn.disabled = false; window.alert(e.message); });
                }
            });
        }

        toolbar = el('div', { class: 'trusted-toolbar' }, [
            el('div', { class: 'trusted-nav' }, [
                el('button', { class: 'button', text: i18n.prevWeek || '← Previous', onclick: function () { state.weekStart = addDays(state.weekStart, -7); render(); } }),
                el('button', { class: 'button', text: i18n.today || 'This week', onclick: function () { state.weekStart = cfg.weekStart; render(); } }),
                el('button', { class: 'button', text: i18n.nextWeek || 'Next →', onclick: function () { state.weekStart = addDays(state.weekStart, 7); render(); } }),
                el('button', { class: 'button trusted-refresh', title: i18n.refresh || 'Refresh', text: i18n.refresh || '⟳ Refresh', onclick: refresh }),
                el('strong', { class: 'trusted-week-label', text: label })
            ]),
            el('div', { class: 'trusted-template-controls' }, [
                // The bulk-assign and save-as-template entries hide while already
                // in bulk mode; the bulk bar's Cancel button is the way back out.
                state.bulk ? null : bulkBtn,
                state.bulk ? null : saveTemplateBtn,
                templateSelect,
                el('label', { class: 'trusted-replace-label' }, [replaceBox, ' ' + (i18n.replace || 'Replace existing slots this week')]),
                applyBtn,
                clearBtn,
                clearAssignmentsBtn
            ])
        ]);

        return toolbar;
    }

    // Inline panel for capturing the current week as a new template. Inserted
    // right after the toolbar; Save posts the week (optionally with its assigned
    // members) and reloads so the new template appears in the dropdown.
    function openSaveTemplate(toolbar) {
        if (document.querySelector('.trusted-save-template')) { return; } // already open

        var nameInput = el('input', { type: 'text', class: 'trusted-template-name-input', placeholder: i18n.templateName || 'Template name' });

        var includeBox = el('input', { type: 'checkbox', id: 'trusted-include-members' });
        includeBox.checked = true;

        var saveBtn = el('button', {
            class: 'button button-primary',
            text: i18n.save || 'Save',
            onclick: function () {
                var name = nameInput.value.trim();
                if (!name) {
                    window.alert(i18n.templateNameRequired || 'Please enter a template name.');
                    nameInput.focus();
                    return;
                }
                saveBtn.disabled = true;
                api('/template-from-week', {
                    method: 'POST',
                    body: { week_start: state.weekStart, title: name, include_members: includeBox.checked }
                }).then(function (res) {
                    state.templates = null; // force a reload so the new template shows up
                    panel.parentNode.removeChild(panel);
                    window.alert((i18n.templateSaved || 'Saved as template "%s".').replace('%s', (res && res.title) || name));
                    render();
                }).catch(function (e) {
                    saveBtn.disabled = false;
                    window.alert(e.message);
                });
            }
        });

        var cancelBtn = el('button', {
            class: 'button',
            text: i18n.cancel || 'Cancel',
            onclick: function () { panel.parentNode.removeChild(panel); }
        });

        var panel = el('div', { class: 'trusted-save-template' }, [
            el('label', { class: 'trusted-save-template-name' }, [(i18n.templateName || 'Template name') + ' ', nameInput]),
            el('label', { class: 'trusted-save-template-include' }, [includeBox, ' ' + (i18n.includeMembers || 'Include assigned members')]),
            el('div', { class: 'trusted-save-template-actions' }, [saveBtn, cancelBtn])
        ]);

        toolbar.parentNode.insertBefore(panel, toolbar.nextSibling);
        nameInput.focus();
    }

    function renderGrid(grid, week) {
        clear(grid);
        (week.days || []).forEach(function (day, idx) {
            grid.appendChild(buildDayColumn(day, idx));
        });
    }

    function toMinutes(hhmm) {
        var parts = (hhmm || '').split(':');
        return (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
    }

    // Accepts 00:00–23:59 plus 24:00 (end of day). One- or two-digit hour.
    function validTime(v) {
        return /^(?:([01]?\d|2[0-3]):[0-5]\d|24:00)$/.test(v);
    }

    function minutesToHHMM(min) {
        if (min >= 1440) { return '24:00'; } // end-of-day boundary
        var h = Math.floor(min / 60), m = min % 60;
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }

    // Uncovered stretches across the whole day, treated as 00:00 → 24:00. Each
    // shift covers [start, end); a shift whose end is at or before its start
    // crosses midnight and so covers through to the end of this day. Overlaps are
    // absorbed (never flagged) by advancing a cursor. Returns gaps as
    // { start, end, before } where `before` is the insert position — the index of
    // the following shift, or shifts.length for an end-of-day gap. A day with no
    // shifts yields a single 00:00–24:00 gap.
    function dayGaps(shifts) {
        var gaps = [];
        var cursor = 0;

        shifts.forEach(function (shift, i) {
            var s = toMinutes(shift.start);
            var e = toMinutes(shift.end);
            if (e <= s) { e = 1440; } // crosses midnight → covers to end of day

            if (s > cursor) {
                gaps.push({ start: minutesToHHMM(cursor), end: minutesToHHMM(s), before: i });
            }
            if (e > cursor) { cursor = e; }
        });

        if (cursor < 1440) {
            gaps.push({ start: minutesToHHMM(cursor), end: minutesToHHMM(1440), before: shifts.length });
        }

        return gaps;
    }

    // Map dayGaps() output by insert position for easy lookup while rendering.
    function gapsByPosition(shifts) {
        var byPos = {};
        dayGaps(shifts).forEach(function (g) { byPos[g.before] = g; });
        return byPos;
    }

    function buildGapMarker(gap) {
        return el('div', {
            class: 'trusted-gap',
            title: i18n.gapAddHint || 'Double-click to add a shift for this gap',
            text: (i18n.gap || 'Gap') + ' ' + gap.start + '–' + gap.end,
            // Double-click a gap to open the add form pre-filled with its times.
            ondblclick: function () {
                if (state.bulk) { return; } // adding is disabled in bulk mode
                var slotsNode = this.closest('.trusted-slots');
                if (!slotsNode) { return; }
                var date = slotsNode.getAttribute('data-date');
                var col = slotsNode.closest('.trusted-day');
                var addBtn = col ? col.querySelector('.trusted-add-slot') : null;
                // Text inputs accept "24:00" directly, so pass the gap as-is. The
                // gap marker (this) is the anchor so the form opens under it.
                showAddSlot(slotsNode, date, addBtn, gap.start, gap.end, this);
            }
        });
    }

    // Rebuild the gap markers in a day's .trusted-slots container from the shift
    // cards currently in it (DOM order). Used after a shift is removed so gaps
    // merge/disappear correctly without a full re-render.
    function refreshGaps(slotsNode) {
        var existing = slotsNode.querySelectorAll('.trusted-gap');
        Array.prototype.forEach.call(existing, function (g) { g.parentNode.removeChild(g); });

        // Real shift cards carry data-start/data-end; the in-progress add form
        // and other children do not, so they're skipped.
        var cards = Array.prototype.filter.call(slotsNode.children, function (c) {
            return c.classList.contains('trusted-slot') && c.hasAttribute('data-start');
        });
        var shifts = cards.map(function (c) {
            return { start: c.getAttribute('data-start'), end: c.getAttribute('data-end') };
        });

        var gapAt = gapsByPosition(shifts);
        cards.forEach(function (card, i) {
            if (gapAt[i]) { slotsNode.insertBefore(buildGapMarker(gapAt[i]), card); }
        });

        var trailing = gapAt[cards.length];
        if (trailing) {
            var marker = buildGapMarker(trailing);
            if (cards.length) { slotsNode.insertBefore(marker, cards[cards.length - 1].nextSibling); }
            else { slotsNode.appendChild(marker); }
        }
    }

    // Insert a shift card into a day's .trusted-slots in start-time order. Times
    // are zero-padded "HH:MM" so a string compare sorts correctly. Gap markers in
    // between don't matter — the caller recomputes gaps afterwards.
    function insertCardSorted(slotsNode, card) {
        var newStart = card.getAttribute('data-start');
        var ref = null;

        Array.prototype.some.call(slotsNode.children, function (c) {
            if (c.classList.contains('trusted-slot') && c.hasAttribute('data-start')
                && c.getAttribute('data-start') > newStart) {
                ref = c;
                return true;
            }
            return false;
        });

        slotsNode.insertBefore(card, ref); // ref null → appended at the end
    }

    function buildDayColumn(day, idx) {
        var col = el('div', { class: 'trusted-day' });
        col.appendChild(el('div', { class: 'trusted-day-head' }, [
            el('span', { class: 'trusted-day-name', text: DAY_NAMES[idx] }),
            el('span', { class: 'trusted-day-date', text: prettyDate(day.date) })
        ]));

        var slots = el('div', { class: 'trusted-slots', 'data-date': day.date });
        var daySlots = day.slots || [];

        // Uncovered time across the full 00:00–24:00 day: before the first shift,
        // between shifts, and after the last shift.
        var gapAt = gapsByPosition(daySlots);
        daySlots.forEach(function (slot, i) {
            if (gapAt[i]) { slots.appendChild(buildGapMarker(gapAt[i])); }
            slots.appendChild(state.bulk ? buildSelectableSlotCard(slot) : buildSlotCard(slot));
        });
        if (gapAt[daySlots.length]) { slots.appendChild(buildGapMarker(gapAt[daySlots.length])); }
        col.appendChild(slots);

        // Add-shift sits at the base of the column, below the shifts. Hidden
        // while bulk-assigning, to keep the focus on ticking slots.
        if (!state.bulk) {
            var addBtn = el('button', {
                class: 'button trusted-add-slot',
                text: i18n.addShift || 'Add Shift',
                onclick: function () { showAddSlot(slots, day.date, addBtn); }
            });
            col.appendChild(addBtn);
        }

        return col;
    }

    function buildSlotCard(slot) {
        // The times ride along on the card so gaps can be recomputed from the
        // DOM (e.g. after a delete) without re-fetching the week.
        var card = el('div', { class: 'trusted-slot', 'data-start': slot.start, 'data-end': slot.end });

        var deleteBtn = el('button', {
            class: 'trusted-slot-delete', title: i18n.remove || 'Remove', text: '×',
            onclick: function () {
                // Confirm next to the × before deleting the shift.
                showConfirm(deleteBtn, i18n.confirmRemove || 'Are you sure?', i18n.delete || 'Delete', i18n.cancel || 'Cancel', function () {
                    api('/rota/' + slot.id, { method: 'DELETE' }).then(function () {
                        var slotsNode = card.parentNode;
                        if (slotsNode) {
                            slotsNode.removeChild(card);
                            refreshGaps(slotsNode); // the removed shift may open or close a gap
                        }
                    }).catch(function (e) { window.alert(e.message); });
                });
            }
        });

        card.appendChild(el('div', { class: 'trusted-slot-time' }, [
            el('span', { text: slot.start + '–' + slot.end }),
            deleteBtn
        ]));

        if (slot.label) {
            card.appendChild(el('div', { class: 'trusted-slot-label', text: slot.label }));
        }

        var assignees = el('div', { class: 'trusted-assignees' });
        var assignArea = el('div', { class: 'trusted-assign-area' });
        card.appendChild(assignees);
        card.appendChild(assignArea);

        // One member per shift. A slot is either filled (show the assignee with
        // a remove button) or empty (show the assign control). Removing the
        // assignee frees the slot and brings the control back without a full
        // re-render, so each shift on each day is managed independently.
        //
        // While a shift is filled, hide the slot's delete (×): a shift with a
        // member on it can't be deleted until that member is removed.
        function paint(assignments) {
            clear(assignees);
            clear(assignArea);

            var filled = !!(assignments && assignments.length);
            deleteBtn.style.display = filled ? 'none' : '';

            // Highlight a shift that still needs cover, so unassigned slots are
            // scannable at a glance. Toggled here so it tracks assign/remove.
            card.classList.toggle('trusted-slot-unassigned', !filled);

            (assignments || []).forEach(function (a) {
                assignees.appendChild(buildAssignee(a, paint));
            });

            if (!filled) {
                assignArea.appendChild(buildAssignControl(slot.id, function (created) {
                    paint(created);
                }));
            }
        }

        paint(slot.assignments || []);
        return card;
    }

    // --- Bulk assign (one member → many shifts in one go) -------------------

    // In bulk mode each shift renders as a selectable card instead of the
    // normal assign control. Empty shifts get a checkbox (and the whole card
    // toggles it); shifts that already have someone are shown dimmed and are
    // not selectable, matching the "skip filled" rule on the server.
    function buildSelectableSlotCard(slot) {
        var filled = !!(slot.assignments && slot.assignments.length);
        var card = el('div', {
            class: 'trusted-slot ' + (filled ? 'trusted-slot-filled' : 'trusted-slot-selectable')
        });

        var timeRow = el('div', { class: 'trusted-slot-time' }, [
            el('span', { text: slot.start + '–' + slot.end })
        ]);

        var checkbox = null;
        if (!filled) {
            checkbox = el('input', { type: 'checkbox', class: 'trusted-bulk-check' });
            checkbox.checked = !!state.bulk.selected[slot.id];
            timeRow.insertBefore(checkbox, timeRow.firstChild);
        }
        card.appendChild(timeRow);

        if (slot.label) {
            card.appendChild(el('div', { class: 'trusted-slot-label', text: slot.label }));
        }

        if (filled) {
            slot.assignments.forEach(function (a) {
                var m = (a && a.member) || {};
                card.appendChild(el('div', { class: 'trusted-assignees' }, [
                    el('div', { class: 'trusted-assignee' }, [
                        el('div', { class: 'trusted-assignee-info' }, [
                            el('span', { class: 'trusted-assignee-name', text: m.name || (i18n.unassigned || 'Unassigned') })
                        ])
                    ])
                ]));
            });
            return card;
        }

        // Clicking anywhere on an empty card toggles its checkbox.
        card.addEventListener('click', function (e) {
            if (e.target !== checkbox) { checkbox.checked = !checkbox.checked; }
            if (checkbox.checked) { state.bulk.selected[slot.id] = true; }
            else { delete state.bulk.selected[slot.id]; }
            updateBulkBar();
        });

        return card;
    }

    function buildBulkBar() {
        var members = state.members || [];

        var select = el('select', { class: 'trusted-assign-select trusted-bulk-member' });
        select.appendChild(el('option', { value: '', text: i18n.selectMember || '— Select a member —' }));
        members.forEach(function (m) {
            select.appendChild(el('option', { value: m.id, text: m.name }));
        });
        select.value = state.bulk.memberId || '';
        select.addEventListener('change', function () {
            state.bulk.memberId = select.value;
            updateBulkBar();
        });

        bulkCountLabel = el('span', { class: 'trusted-bulk-count' });

        bulkAssignBtn = el('button', {
            class: 'button button-primary trusted-bulk-confirm',
            text: i18n.assign || 'Assign',
            onclick: doBulkAssign
        });

        var cancelBtn = el('button', {
            class: 'button trusted-bulk-cancel',
            text: i18n.cancel || 'Cancel',
            onclick: function () { state.bulk = null; render(); }
        });

        return el('div', { class: 'trusted-bulk-bar' }, [
            el('span', { class: 'trusted-bulk-hint', text: i18n.bulkHint || 'Pick a member, tick the empty shifts to fill, then Assign.' }),
            el('div', { class: 'trusted-bulk-controls' }, [
                select,
                bulkCountLabel,
                bulkAssignBtn,
                cancelBtn
            ])
        ]);
    }

    function updateBulkBar() {
        if (!state.bulk || !bulkCountLabel) { return; }

        var count = Object.keys(state.bulk.selected).length;
        bulkCountLabel.textContent = count === 1
            ? (i18n.oneSelected || '1 shift selected')
            : (i18n.manySelected || '%d shifts selected').replace('%d', count);

        if (bulkAssignBtn) {
            bulkAssignBtn.disabled = !(state.bulk.memberId && count > 0);
        }
    }

    function doBulkAssign() {
        var memberId = state.bulk.memberId;
        var rotaIds = Object.keys(state.bulk.selected).map(function (k) { return parseInt(k, 10); });
        if (!memberId || !rotaIds.length) { return; }

        bulkAssignBtn.disabled = true;
        api('/assignments', { method: 'POST', body: { member_id: memberId, rota_ids: rotaIds } })
            .then(function (res) {
                var skipped = (res && res.skipped) || [];
                state.bulk = null;
                render();
                if (skipped.length) {
                    window.alert((i18n.bulkSkipped || '%d shift(s) were already filled and left unchanged.').replace('%d', skipped.length));
                }
            })
            .catch(function (e) {
                bulkAssignBtn.disabled = false;
                window.alert(e.message);
            });
    }

    function buildAssignee(assignment, repaint) {
        var m = assignment.member || {};
        var name = m.name || (i18n.unassigned || 'Unknown');
        var meta = [];
        if (m.telephone) { meta.push(m.telephone); }
        if (m.email) { meta.push(m.email); }

        var removeBtn = el('button', {
            class: 'trusted-assignee-remove', title: i18n.remove || 'Remove', text: '×',
            onclick: function () {
                // Confirm next to the × before actually removing.
                showConfirm(removeBtn, i18n.confirmRemove || 'Are you sure?', i18n.remove || 'Remove', i18n.cancel || 'Cancel', function () {
                    removeBtn.disabled = true;
                    api('/assignment/' + assignment.id, { method: 'DELETE' })
                        .then(function () { repaint([]); }) // slot is now empty
                        .catch(function (e) {
                            removeBtn.disabled = false;
                            window.alert(e.message);
                        });
                });
            }
        });

        return el('div', { class: 'trusted-assignee' }, [
            el('div', { class: 'trusted-assignee-info' }, [
                el('span', { class: 'trusted-assignee-name', text: name }),
                meta.length ? el('span', { class: 'trusted-assignee-meta', text: meta.join(' · ') }) : null
            ]),
            removeBtn
        ]);
    }

    // Tracks the single open assign picker on the page. Opening another, or a
    // full re-render, closes whatever is currently open.
    var closeOpenPicker = null;

    function buildAssignControl(rotaId, onAssigned) {
        var wrap = el('div', { class: 'trusted-assign' });
        var picker = null;

        var openBtn = el('button', {
            class: 'button trusted-assign-btn',
            text: i18n.assign || 'Assign'
        });

        function close() {
            if (picker) { wrap.removeChild(picker); picker = null; }
            openBtn.style.display = '';
            if (closeOpenPicker === close) { closeOpenPicker = null; }
        }

        function open() {
            // Only one assign control may be open on the page at a time.
            if (closeOpenPicker) { closeOpenPicker(); }

            var members = state.members || [];

            var select = el('select', { class: 'trusted-assign-select' });
            select.appendChild(el('option', { value: '', text: i18n.selectMember || '— Select a member —' }));
            members.forEach(function (m) {
                select.appendChild(el('option', { value: m.id, text: m.name }));
            });

            var addBtn = el('button', {
                class: 'button button-primary trusted-assign-confirm',
                text: i18n.assign || 'Assign',
                onclick: function () {
                    var id = select.value;
                    if (!id) { return; }

                    addBtn.disabled = true;
                    api('/assignment', { method: 'POST', body: { rota_id: rotaId, member_id: id } })
                        .then(function (res) {
                            close();
                            onAssigned(res.created || []);
                        })
                        .catch(function (e) {
                            addBtn.disabled = false;
                            window.alert(e.message);
                        });
                }
            });

            var cancelBtn = el('button', {
                class: 'button trusted-assign-cancel',
                text: i18n.cancel || 'Cancel',
                onclick: close
            });

            picker = el('div', { class: 'trusted-assign-picker' }, [
                select,
                el('div', { class: 'trusted-assign-actions' }, [addBtn, cancelBtn])
            ]);

            openBtn.style.display = 'none';
            wrap.appendChild(picker);
            closeOpenPicker = close;
            select.focus();
        }

        openBtn.addEventListener('click', open);
        wrap.appendChild(openBtn);
        return wrap;
    }

    function showAddSlot(slotsNode, date, addBtn, startTime, endTime, anchor) {
        // Only one add form per day at a time. If one is already open (e.g. a gap
        // was double-clicked while adding), just focus its name field.
        var openForm = slotsNode.querySelector('.trusted-slot-new .trusted-label-input');
        if (openForm) { openForm.focus(); return; }

        // Disable the day's Add Shift button while the form is open, and restore
        // it when the form closes.
        if (addBtn) { addBtn.disabled = true; }
        function closeForm() {
            if (form.parentNode) { form.parentNode.removeChild(form); }
            if (addBtn) { addBtn.disabled = false; }
        }

        // Text inputs (not <input type=time>) so the full 00:00–24:00 range is
        // allowed — a time input can't represent 24:00 (end of day).
        var start = el('input', { type: 'text', class: 'trusted-time-input', inputmode: 'numeric', maxlength: '5', placeholder: 'HH:MM', value: startTime || '09:00' });
        var end = el('input', { type: 'text', class: 'trusted-time-input', inputmode: 'numeric', maxlength: '5', placeholder: 'HH:MM', value: endTime || '17:00' });
        var label = el('input', { type: 'text', class: 'trusted-label-input', required: 'required', placeholder: i18n.newSlotLabel || 'Shift name' });

        // Optional member to assign straight away. Leaving it on the blank option
        // creates the shift unassigned.
        var memberSelect = el('select', { class: 'trusted-assign-select trusted-new-member-select' });
        memberSelect.appendChild(el('option', { value: '', text: i18n.selectMember || '— Select a member —' }));
        (state.members || []).forEach(function (m) {
            memberSelect.appendChild(el('option', { value: m.id, text: m.name }));
        });

        function finish(slot) {
            if (form.parentNode) { form.parentNode.removeChild(form); }
            insertCardSorted(slotsNode, buildSlotCard(slot)); // place it in time order
            refreshGaps(slotsNode); // the new shift may fill or split a gap
            if (addBtn) { addBtn.disabled = false; } // form is gone; allow adding another
        }

        var form = el('div', { class: 'trusted-slot trusted-slot-new' }, [
            el('div', { class: 'trusted-new-title', text: i18n.addingShift || 'Adding Shift' }),
            label,
            el('div', { class: 'trusted-new-times' }, [
                el('label', { class: 'trusted-new-time' }, [(i18n.newSlotStart || 'Start') + ' ', start]),
                el('label', { class: 'trusted-new-time' }, [(i18n.newSlotEnd || 'End') + ' ', end])
            ]),
            el('label', { class: 'trusted-new-member' }, [(i18n.memberOptional || 'Member (optional)'), memberSelect]),
            el('div', { class: 'trusted-new-actions' }, [
                el('button', {
                    class: 'button button-small button-primary', text: i18n.save || 'Save',
                    onclick: function () {
                        var name = label.value.trim();
                        if (!name) {
                            window.alert(i18n.nameRequired || 'Please enter a shift name.');
                            label.focus();
                            return;
                        }
                        var startVal = start.value.trim();
                        var endVal = end.value.trim();
                        if (!validTime(startVal) || !validTime(endVal)) {
                            window.alert(i18n.invalidTime || 'Enter times as HH:MM, between 00:00 and 24:00.');
                            (validTime(startVal) ? end : start).focus();
                            return;
                        }
                        api('/rota', { method: 'POST', body: { date: date, start: startVal, end: endVal, label: name } })
                            .then(function (slot) {
                                var memberId = memberSelect.value;
                                if (!memberId) { finish(slot); return; }

                                // Shift created — now assign the chosen member. If
                                // that fails, keep the (unassigned) shift and report.
                                api('/assignment', { method: 'POST', body: { rota_id: slot.id, member_id: memberId } })
                                    .then(function (res) {
                                        slot.assignments = (res && res.created) || [];
                                        finish(slot);
                                    })
                                    .catch(function (e) {
                                        finish(slot);
                                        window.alert(e.message);
                                    });
                            })
                            .catch(function (e) { window.alert(e.message); });
                    }
                }),
                el('button', { class: 'button button-small', text: i18n.cancel || 'Cancel', onclick: closeForm })
            ])
        ]);
        // Open the form under the double-clicked gap when there's an anchor,
        // otherwise at the bottom of the slots (above the Add Shift button).
        if (anchor && anchor.parentNode === slotsNode) {
            slotsNode.insertBefore(form, anchor.nextSibling);
        } else {
            slotsNode.appendChild(form);
        }
        label.focus();
    }

    render();
})();
