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

    var state = {
        weekStart: cfg.weekStart,
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

    // --- Rendering ----------------------------------------------------------

    function render() {
        closeOpenPicker = null; // the DOM is about to be rebuilt
        clear(root);
        root.appendChild(el('p', { class: 'trusted-loading', text: 'Loading rota…' }));

        // Load week data, members and templates BEFORE building the toolbar, so
        // the template dropdown is populated when it is first rendered.
        Promise.all([api('/week/' + state.weekStart), loadMembers(), loadTemplates()])
            .then(function (results) {
                clear(root);
                root.appendChild(buildToolbar());
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

    function buildToolbar() {
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

        toolbar = el('div', { class: 'trusted-toolbar' }, [
            el('div', { class: 'trusted-nav' }, [
                el('button', { class: 'button', text: i18n.prevWeek || '← Previous', onclick: function () { state.weekStart = addDays(state.weekStart, -7); render(); } }),
                el('button', { class: 'button', text: i18n.today || 'This week', onclick: function () { state.weekStart = cfg.weekStart; render(); } }),
                el('button', { class: 'button', text: i18n.nextWeek || 'Next →', onclick: function () { state.weekStart = addDays(state.weekStart, 7); render(); } }),
                el('strong', { class: 'trusted-week-label', text: label })
            ]),
            el('div', { class: 'trusted-template-controls' }, [
                // The bulk-assign and save-as-template entries hide while already
                // in bulk mode; the bulk bar's Cancel button is the way back out.
                state.bulk ? null : bulkBtn,
                state.bulk ? null : saveTemplateBtn,
                templateSelect,
                el('label', { class: 'trusted-replace-label' }, [replaceBox, ' ' + (i18n.replace || 'Replace existing slots this week')]),
                applyBtn
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

    function buildDayColumn(day, idx) {
        var col = el('div', { class: 'trusted-day' });
        col.appendChild(el('div', { class: 'trusted-day-head' }, [
            el('span', { class: 'trusted-day-name', text: DAY_NAMES[idx] }),
            el('span', { class: 'trusted-day-date', text: prettyDate(day.date) })
        ]));

        var slots = el('div', { class: 'trusted-slots' });
        (day.slots || []).forEach(function (slot) {
            slots.appendChild(state.bulk ? buildSelectableSlotCard(slot) : buildSlotCard(slot));
        });
        col.appendChild(slots);

        // Adding shifts is disabled while bulk-assigning, to keep the focus on
        // ticking existing slots.
        if (!state.bulk) {
            col.appendChild(el('button', {
                class: 'button-link trusted-add-slot',
                text: i18n.addShift || '+ Add shift',
                onclick: function () { showAddSlot(slots, day.date); }
            }));
        }

        return col;
    }

    function buildSlotCard(slot) {
        var card = el('div', { class: 'trusted-slot' });

        var deleteBtn = el('button', {
            class: 'trusted-slot-delete', title: i18n.remove || 'Remove', text: '×',
            onclick: function () {
                if (!window.confirm(i18n.confirmDelete || 'Delete this slot?')) { return; }
                api('/rota/' + slot.id, { method: 'DELETE' }).then(function () {
                    card.parentNode.removeChild(card);
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

            (assignments || []).forEach(function (a) {
                assignees.appendChild(buildAssignee(a, function () { paint([]); }));
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

    function buildAssignee(assignment, onRemoved) {
        var m = assignment.member || {};
        var name = m.name || (i18n.unassigned || 'Unknown');
        var meta = [];
        if (m.telephone) { meta.push(m.telephone); }
        if (m.email) { meta.push(m.email); }

        var removeBtn = el('button', {
            class: 'trusted-assignee-remove', title: i18n.remove || 'Remove', text: '×',
            onclick: function () {
                removeBtn.disabled = true;
                api('/assignment/' + assignment.id, { method: 'DELETE' }).then(function () {
                    if (onRemoved) { onRemoved(); }
                }).catch(function (e) {
                    removeBtn.disabled = false;
                    window.alert(e.message);
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

    function showAddSlot(slotsNode, date) {
        var start = el('input', { type: 'time', class: 'trusted-time-input', value: '09:00' });
        var end = el('input', { type: 'time', class: 'trusted-time-input', value: '17:00' });
        var label = el('input', { type: 'text', class: 'trusted-label-input', required: 'required', placeholder: i18n.newSlotLabel || 'Shift name' });

        var form = el('div', { class: 'trusted-slot trusted-slot-new' }, [
            el('div', { class: 'trusted-new-times' }, [start, ' – ', end]),
            label,
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
                        api('/rota', { method: 'POST', body: { date: date, start: start.value, end: end.value, label: name } })
                            .then(function (slot) {
                                slotsNode.replaceChild(buildSlotCard(slot), form);
                            })
                            .catch(function (e) { window.alert(e.message); });
                    }
                }),
                el('button', { class: 'button button-small', text: i18n.cancel || 'Cancel', onclick: function () { slotsNode.removeChild(form); } })
            ])
        ]);
        slotsNode.appendChild(form);
        label.focus();
    }

    render();
})();
