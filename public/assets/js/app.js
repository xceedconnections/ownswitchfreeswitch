/* Ownswitch CRM UI helpers. No inline JS; no innerHTML with untrusted data. */
(function () {
    'use strict';

    document.addEventListener('submit', function (ev) {
        var msg = ev.target && ev.target.getAttribute ? ev.target.getAttribute('data-confirm') : null;
        if (msg && !window.confirm(msg)) {
            ev.preventDefault();
        }
    });

    document.addEventListener('submit', function (ev) {
        if (ev.defaultPrevented) { return; }
        var btns = ev.target.querySelectorAll('button[type="submit"][data-once]');
        btns.forEach(function (b) {
            window.setTimeout(function () {
                b.disabled = true;
                b.classList.add('disabled');
            }, 0);
        });
    });

    document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () { if (sel.form) { sel.form.submit(); } });
    });

    // Close mobile offcanvas after nav click; keep accordion chevron state in sync
    var sidebar = document.getElementById('osSidebar');
    if (sidebar && window.bootstrap) {
        sidebar.querySelectorAll('a.os-nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                var inst = bootstrap.Offcanvas.getInstance(sidebar);
                if (inst && window.matchMedia('(max-width: 991.98px)').matches) {
                    inst.hide();
                }
            });
        });
        sidebar.querySelectorAll('.os-nav-group .collapse').forEach(function (el) {
            el.addEventListener('show.bs.collapse', function () {
                var g = el.closest('.os-nav-group');
                if (g) { g.classList.add('is-open'); }
            });
            el.addEventListener('hide.bs.collapse', function () {
                var g = el.closest('.os-nav-group');
                if (g) { g.classList.remove('is-open'); }
            });
        });
    }

    var form = document.getElementById('billing-preview-form');
    var hint = document.getElementById('billing-live-hint');
    if (form && hint) {
        var calc = function () {
            var I = parseInt(form.elements.initial.value, 10);
            var S = parseInt(form.elements.subsequent.value, 10);
            var D = parseInt(form.elements.duration.value, 10);
            if (!(I > 0) || !(S > 0) || !(D >= 0)) { hint.textContent = ''; return; }
            var b = D === 0 ? 0 : (D <= I ? I : I + Math.ceil((D - I) / S) * S);
            hint.textContent = 'Estimated billable seconds: ' + b + ' (server result below is authoritative)';
        };
        form.addEventListener('input', calc);
        calc();
    }

    /* Magnus-style multi-select chips (codecs, etc.) — textContent only, no innerHTML */
    document.querySelectorAll('[data-os-multiselect]').forEach(function (wrap) {
        if (wrap.getAttribute('data-readonly') === '1') { return; }
        var hidden = wrap.querySelector('input[type="hidden"]');
        var chips = wrap.querySelector('[data-chips]');
        var add = wrap.querySelector('[data-add]');
        var tpl = wrap.querySelector('[data-chip-tpl]');
        if (!hidden || !chips || !add || !tpl) { return; }

        var sync = function () {
            var vals = [];
            chips.querySelectorAll('.os-ms-chip').forEach(function (chip) {
                var v = chip.getAttribute('data-value');
                if (v) { vals.push(v); }
            });
            hidden.value = vals.join(',');
            Array.prototype.forEach.call(add.options, function (opt) {
                if (!opt.value) { return; }
                opt.disabled = vals.indexOf(opt.value) !== -1;
            });
            add.value = '';
        };

        add.addEventListener('change', function () {
            var v = add.value;
            if (!v) { return; }
            var already = false;
            chips.querySelectorAll('.os-ms-chip').forEach(function (chip) {
                if (chip.getAttribute('data-value') === v) { already = true; }
            });
            if (already) { sync(); return; }
            var label = add.options[add.selectedIndex] ? add.options[add.selectedIndex].textContent : v;
            var node = tpl.content.firstElementChild.cloneNode(true);
            node.setAttribute('data-value', v);
            node.querySelector('.os-ms-chip-label').textContent = label;
            var btn = node.querySelector('[data-remove]');
            if (btn) { btn.setAttribute('aria-label', 'Remove ' + v); }
            chips.appendChild(node);
            sync();
        });

        chips.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-remove]');
            if (!btn || !chips.contains(btn)) { return; }
            var chip = btn.closest('.os-ms-chip');
            if (chip) { chip.remove(); }
            sync();
        });

        sync();
    });

    /* Trunk-group waterfall: drag/drop order + on/off + last-hour sort presets */
    document.querySelectorAll('[data-os-waterfall]').forEach(function (root) {
        if (root.getAttribute('data-readonly') === '1') { return; }
        var onList = root.querySelector('[data-wf-on]');
        var offList = root.querySelector('[data-wf-off]');
        var onInput = root.querySelector('[data-wf-on-input]');
        var offInput = root.querySelector('[data-wf-off-input]');
        var add = root.querySelector('[data-wf-add]');
        var flow = root.querySelector('[data-wf-flow]');
        var tpl = root.querySelector('[data-wf-row-tpl]');
        if (!onList || !offList || !onInput || !offInput || !tpl) { return; }

        var dragEl = null;

        var ordinal = function (n) {
            var v = n % 100;
            if (v >= 11 && v <= 13) { return n + 'th'; }
            return n + (['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][n % 10] || 'th');
        };

        var clearEmpty = function () {
            var empty = root.querySelector('[data-wf-empty-on]');
            if (onList.children.length === 0) {
                if (!empty) {
                    empty = document.createElement('div');
                    empty.className = 'os-wf-empty';
                    empty.setAttribute('data-wf-empty-on', '1');
                    empty.textContent = 'No trunks on yet — add one below, then drag to set order.';
                    onList.insertAdjacentElement('afterend', empty);
                }
            } else if (empty) {
                empty.remove();
            }
        };

        var refreshRanks = function () {
            var best = null;
            var bestAsr = -1;
            Array.prototype.forEach.call(onList.querySelectorAll('[data-wf-row]'), function (row, i) {
                var rank = row.querySelector('[data-wf-rank]');
                if (rank) {
                    rank.textContent = ordinal(i + 1);
                    rank.classList.remove('is-off');
                }
                row.classList.remove('is-off');
                var tog = row.querySelector('[data-wf-toggle]');
                if (tog) { tog.checked = true; }
                var asr = parseFloat(row.getAttribute('data-asr') || '0');
                var attempts = parseInt(row.getAttribute('data-attempts') || '0', 10);
                if (attempts > 0 && asr > bestAsr) {
                    bestAsr = asr;
                    best = row;
                }
            });
            Array.prototype.forEach.call(offList.querySelectorAll('[data-wf-row]'), function (row) {
                var rank = row.querySelector('[data-wf-rank]');
                if (rank) {
                    rank.textContent = 'Off';
                    rank.classList.add('is-off');
                }
                row.classList.add('is-off');
                var tog = row.querySelector('[data-wf-toggle]');
                if (tog) { tog.checked = false; }
                var top = row.querySelector('[data-wf-top]');
                if (top) { top.hidden = true; }
            });
            Array.prototype.forEach.call(onList.querySelectorAll('[data-wf-row]'), function (row) {
                var top = row.querySelector('[data-wf-top]');
                if (top) { top.hidden = row !== best; }
            });
        };

        var sync = function () {
            var onIds = [];
            var names = [];
            Array.prototype.forEach.call(onList.querySelectorAll('[data-wf-row]'), function (row) {
                var id = row.getAttribute('data-id');
                if (id) {
                    onIds.push(id);
                    var nm = row.querySelector('.os-wf-name, [data-wf-name]');
                    names.push(nm ? nm.textContent : ('#' + id));
                }
            });
            var offIds = [];
            Array.prototype.forEach.call(offList.querySelectorAll('[data-wf-row]'), function (row) {
                var id = row.getAttribute('data-id');
                if (id) { offIds.push(id); }
            });
            onInput.value = onIds.join(',');
            offInput.value = offIds.join(',');
            if (flow) {
                flow.textContent = '';
                if (names.length === 0) {
                    flow.textContent = 'Add trunks to build your waterfall.';
                } else {
                    var lead = document.createElement('span');
                    lead.textContent = 'Each call → ';
                    flow.appendChild(lead);
                    names.forEach(function (name, i) {
                        if (i > 0) {
                            var arrow = document.createElement('span');
                            arrow.textContent = ' → ';
                            flow.appendChild(arrow);
                        }
                        var step = document.createElement('span');
                        step.className = 'os-wf-step';
                        var em = document.createElement('em');
                        em.textContent = String(i + 1);
                        step.appendChild(em);
                        var strong = document.createElement('strong');
                        strong.textContent = name;
                        step.appendChild(strong);
                        flow.appendChild(step);
                    });
                    var stop = document.createElement('span');
                    stop.textContent = ' → then stops';
                    flow.appendChild(stop);
                }
            }
            if (add) {
                var used = onIds.concat(offIds);
                Array.prototype.forEach.call(add.options, function (opt) {
                    if (!opt.value) { return; }
                    opt.disabled = used.indexOf(opt.value) !== -1;
                });
                add.value = '';
            }
            refreshRanks();
            clearEmpty();
        };

        var bindRow = function (row) {
            row.setAttribute('draggable', 'true');
            row.addEventListener('dragstart', function (ev) {
                dragEl = row;
                row.classList.add('is-dragging');
                if (ev.dataTransfer) {
                    ev.dataTransfer.effectAllowed = 'move';
                    try { ev.dataTransfer.setData('text/plain', row.getAttribute('data-id') || ''); } catch (e) {}
                }
            });
            row.addEventListener('dragend', function () {
                row.classList.remove('is-dragging');
                root.querySelectorAll('.is-drag-over').forEach(function (el) { el.classList.remove('is-drag-over'); });
                dragEl = null;
                sync();
            });
            var tog = row.querySelector('[data-wf-toggle]');
            if (tog) {
                tog.addEventListener('change', function () {
                    if (tog.checked) {
                        onList.appendChild(row);
                    } else {
                        offList.appendChild(row);
                    }
                    sync();
                });
            }
        };

        var placeBefore = function (list, target, moving) {
            if (!moving || moving === target) { return; }
            var rect = target.getBoundingClientRect();
            var before = (window.event && window.event.clientY < rect.top + rect.height / 2);
            // fallback: use last dragover Y stored on element
            var y = target._wfY;
            if (typeof y === 'number') {
                before = y < rect.top + rect.height / 2;
            }
            if (before) {
                list.insertBefore(moving, target);
            } else {
                list.insertBefore(moving, target.nextSibling);
            }
        };

        var bindList = function (list) {
            list.addEventListener('dragover', function (ev) {
                ev.preventDefault();
                if (!dragEl) { return; }
                var target = ev.target.closest('[data-wf-row]');
                list.querySelectorAll('.is-drag-over').forEach(function (el) { el.classList.remove('is-drag-over'); });
                if (target && list.contains(target) && target !== dragEl) {
                    target._wfY = ev.clientY;
                    target.classList.add('is-drag-over');
                    placeBefore(list, target, dragEl);
                } else if (!target) {
                    list.appendChild(dragEl);
                }
            });
            list.addEventListener('drop', function (ev) {
                ev.preventDefault();
                sync();
            });
        };

        bindList(onList);
        bindList(offList);
        onList.querySelectorAll('[data-wf-row]').forEach(bindRow);
        offList.querySelectorAll('[data-wf-row]').forEach(bindRow);

        root.querySelectorAll('[data-wf-sort]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-wf-sort');
                var attr = key === 'long' ? 'data-long-acd' : 'data-asr';
                var rows = Array.prototype.slice.call(onList.querySelectorAll('[data-wf-row]'));
                rows.sort(function (a, b) {
                    var av = parseFloat(a.getAttribute(attr) || '0');
                    var bv = parseFloat(b.getAttribute(attr) || '0');
                    if (bv !== av) { return bv - av; }
                    return parseInt(b.getAttribute('data-attempts') || '0', 10) - parseInt(a.getAttribute('data-attempts') || '0', 10);
                });
                rows.forEach(function (row) { onList.appendChild(row); });
                sync();
            });
        });

        if (add) {
            add.addEventListener('change', function () {
                var v = add.value;
                if (!v) { return; }
                var opt = add.options[add.selectedIndex];
                var node = tpl.content.firstElementChild.cloneNode(true);
                node.setAttribute('data-id', v);
                node.setAttribute('data-asr', opt.getAttribute('data-asr') || '0');
                node.setAttribute('data-acd', opt.getAttribute('data-acd') || '0');
                node.setAttribute('data-long-acd', opt.getAttribute('data-long-acd') || '0');
                node.setAttribute('data-attempts', opt.getAttribute('data-attempts') || '0');
                var nameEl = node.querySelector('[data-wf-name]');
                if (nameEl) { nameEl.textContent = opt.getAttribute('data-name') || opt.textContent; }
                var car = node.querySelector('[data-wf-carrier]');
                var carrier = opt.getAttribute('data-carrier') || '';
                if (car) {
                    if (carrier) {
                        car.textContent = carrier;
                        car.hidden = false;
                    } else {
                        car.hidden = true;
                    }
                }
                var asr = parseFloat(opt.getAttribute('data-asr') || '0');
                var meter = node.querySelector('[data-wf-meter]');
                var fill = node.querySelector('[data-wf-meter-fill]');
                if (meter) {
                    meter.classList.remove('is-good', 'is-mid', 'is-low');
                    meter.classList.add(asr >= 70 ? 'is-good' : (asr >= 40 ? 'is-mid' : 'is-low'));
                }
                if (fill) { fill.style.width = Math.max(0, Math.min(100, asr)) + '%'; }
                var asrEl = node.querySelector('[data-wf-asr]');
                if (asrEl) { asrEl.textContent = String(Math.round(asr)); }
                var ansEl = node.querySelector('[data-wf-answered]');
                if (ansEl) { ansEl.textContent = opt.getAttribute('data-answered') || '0'; }
                var failEl = node.querySelector('[data-wf-failed]');
                if (failEl) { failEl.textContent = opt.getAttribute('data-failed') || '0'; }
                var acdEl = node.querySelector('[data-wf-acd-label]');
                if (acdEl) { acdEl.textContent = opt.getAttribute('data-acd-label') || '0s'; }
                var attEl = node.querySelector('[data-wf-attempts]');
                if (attEl) { attEl.textContent = opt.getAttribute('data-attempts') || '0'; }
                var shareEl = node.querySelector('[data-wf-share]');
                if (shareEl) { shareEl.textContent = String(Math.round(parseFloat(opt.getAttribute('data-share') || '0'))); }
                var tog = node.querySelector('[data-wf-toggle]');
                if (tog) { tog.setAttribute('aria-label', 'Use ' + (opt.getAttribute('data-name') || v) + ' for calls'); }
                onList.appendChild(node);
                bindRow(node);
                sync();
            });
        }

        sync();
    });

    /* Live concurrent CC pill (allowed / using) — poll every 10s */
    document.querySelectorAll('[data-os-cc]').forEach(function (el) {
        var url = el.getAttribute('data-url');
        var out = el.querySelector('[data-os-cc-value]');
        if (!url || !out) { return; }
        var tick = function () {
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data) { return; }
                    var allowed = data.unlimited ? '∞' : String(data.allowed != null ? data.allowed : '—');
                    out.textContent = allowed + ' / ' + String(data.using != null ? data.using : '—');
                })
                .catch(function () { /* keep last */ });
        };
        tick();
        window.setInterval(tick, 10000);
    });

    /* Admin host resources — poll every 10s */
    document.querySelectorAll('[data-os-host]').forEach(function (root) {
        var url = root.getAttribute('data-url');
        if (!url) { return; }
        var setMeter = function (key, pct) {
            var meter = root.querySelector('[data-h-meter="' + key + '"]');
            if (!meter) { return; }
            var fill = meter.querySelector('span');
            var n = Math.max(0, Math.min(100, Number(pct) || 0));
            if (fill) { fill.style.width = n + '%'; }
            meter.classList.remove('is-warn', 'is-hot');
            if (n >= 90) { meter.classList.add('is-hot'); }
            else if (n >= 75) { meter.classList.add('is-warn'); }
        };
        var tick = function () {
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data) { return; }
                    Object.keys(data).forEach(function (k) {
                        root.querySelectorAll('[data-h="' + k + '"]').forEach(function (node) {
                            node.textContent = String(data[k]);
                        });
                    });
                    setMeter('cpu_pct', data.cpu_pct);
                    setMeter('ram_pct', data.ram_pct);
                    setMeter('disk_pct', data.disk_pct);
                })
                .catch(function () { /* keep last */ });
        };
        tick();
        window.setInterval(tick, 10000);
    });

    // SIP Users: Dynamic vs IP based — toggle password / host fields
    document.querySelectorAll('[data-os-sip-auth]').forEach(function (root) {
        var modeEl = root.querySelector('#f_connection_mode');
        var hostEl = root.querySelector('#f_sip_host');
        var passWrap = root.querySelector('#f_password');
        var hostWrap = hostEl ? hostEl.closest('.col-12, .col-md-6') : null;
        var passCol = passWrap ? passWrap.closest('.col-12, .col-md-6') : null;
        var portEl = root.querySelector('#f_sip_port');
        if (!modeEl) { return; }

        var apply = function () {
            var mode = String(modeEl.value || 'dynamic');
            var ip = mode === 'ip';
            if (passCol) {
                passCol.classList.toggle('d-none', ip);
                if (passWrap) {
                    passWrap.required = !ip && passWrap.hasAttribute('data-force-required');
                    if (ip) { passWrap.value = ''; }
                }
            }
            if (hostEl) {
                hostEl.readOnly = !ip;
                if (!ip) {
                    hostEl.value = 'dynamic';
                    hostEl.placeholder = 'dynamic';
                } else if (String(hostEl.value).toLowerCase() === 'dynamic') {
                    hostEl.value = '';
                    hostEl.placeholder = 'One IP per line\n192.0.2.10\n203.0.113.20';
                }
            }
            if (hostWrap) {
                var label = hostWrap.querySelector('label');
                if (label) {
                    label.textContent = ip ? 'Allowed IP(s)' : 'Allowed IP(s)';
                }
                var help = hostWrap.querySelector('.form-text');
                if (help && ip) {
                    help.textContent = 'One IPv4/IPv6 per line (or commas). All listed IPs are authorized for this SIP user.';
                }
            }
            if (portEl) {
                var portCol = portEl.closest('.col-12, .col-md-6');
                if (portCol) { portCol.classList.toggle('opacity-75', !ip); }
            }
        };

        modeEl.addEventListener('change', function () {
            if (String(modeEl.value) === 'dynamic' && String(hostEl && hostEl.value || '').toLowerCase() !== 'dynamic') {
                if (passWrap) {
                    passWrap.setAttribute('data-force-required', '1');
                    passWrap.required = true;
                    window.alert('Dynamic mode needs a new SIP password. Enter one on the Account tab before saving.');
                }
            }
            apply();
        });
        apply();
    });
})();

/* Recording player and row checkboxes. Kept in this file because CSP blocks inline scripts. */
(function () {
    'use strict';

    var bar = document.getElementById('os-player');
    var audio = document.getElementById('os-player-audio');
    var label = document.getElementById('os-player-label');
    var dl = document.getElementById('os-player-dl');
    if (bar && audio && label && dl) {
        audio.addEventListener('error', function () {
            label.textContent = 'This recording could not be played';
        });
        document.addEventListener('click', function (ev) {
            var btn = ev.target.closest ? ev.target.closest('[data-os-play]') : null;
            if (!btn) { return; }
            ev.preventDefault();
            var src = btn.getAttribute('data-src') || '';
            var down = btn.getAttribute('data-download') || src;
            var text = btn.getAttribute('data-label') || 'Recording';
            if (!src) { return; }
            bar.hidden = false;
            document.body.style.paddingBottom = '4.5rem';
            label.textContent = text;
            dl.href = down;
            audio.pause();
            audio.src = src;
            audio.load();
            var play = audio.play();
            if (play && play.catch) {
                play.catch(function () {
                    label.textContent = 'Press play on the player';
                });
            }
        });
    }

    var cdrAll = document.getElementById('cdr-check-all');
    if (cdrAll) {
        cdrAll.addEventListener('change', function () {
            document.querySelectorAll('.cdr-row-check').forEach(function (cb) { cb.checked = cdrAll.checked; });
        });
    }

    var recForm = document.getElementById('rec-bulk-form');
    var recAll = document.getElementById('rec-check-all');
    var recBulk = document.getElementById('rec-delete-selected');
    if (recForm) {
        var syncRec = function () {
            var boxes = recForm.querySelectorAll('.rec-row-check');
            var n = 0;
            boxes.forEach(function (cb) { if (cb.checked) { n++; } });
            if (recBulk) {
                recBulk.disabled = n === 0;
                recBulk.textContent = n > 0 ? 'Delete selected (' + n + ')' : 'Delete selected';
            }
            if (recAll) { recAll.checked = boxes.length > 0 && n === boxes.length; }
        };
        if (recAll) {
            recAll.addEventListener('change', function () {
                recForm.querySelectorAll('.rec-row-check').forEach(function (cb) { cb.checked = recAll.checked; });
                syncRec();
            });
        }
        recForm.querySelectorAll('.rec-row-check').forEach(function (cb) {
            cb.addEventListener('change', syncRec);
        });
        recForm.addEventListener('submit', function (ev) {
            var btn = ev.submitter;
            if (btn && btn.getAttribute('data-bulk') === '1' && !recForm.querySelector('.rec-row-check:checked')) {
                ev.preventDefault();
                ev.stopImmediatePropagation();
                return;
            }
            var msg = btn && btn.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                ev.preventDefault();
                ev.stopImmediatePropagation();
            }
        });
    }

    var bulkAll = document.getElementById('os-bulk-all');
    var bulkBoxes = document.querySelectorAll('.os-bulk-id');
    if (bulkAll && bulkBoxes.length) {
        var syncBulkAll = function () {
            var n = 0;
            bulkBoxes.forEach(function (cb) { if (cb.checked) { n++; } });
            bulkAll.checked = n === bulkBoxes.length;
            bulkAll.indeterminate = n > 0 && n < bulkBoxes.length;
        };
        bulkAll.addEventListener('change', function () {
            bulkBoxes.forEach(function (cb) { cb.checked = bulkAll.checked; });
            bulkAll.indeterminate = false;
        });
        bulkBoxes.forEach(function (cb) {
            cb.addEventListener('change', syncBulkAll);
        });
    }

    var batchForm = document.getElementById('os-batch-rate-form');
    if (batchForm) {
        batchForm.addEventListener('submit', function (ev) {
            var box = document.getElementById('os-batch-rate-ids');
            if (!box) { return; }
            while (box.firstChild) { box.removeChild(box.firstChild); }
            var n = 0;
            document.querySelectorAll('.os-bulk-id:checked').forEach(function (cb) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = cb.value;
                box.appendChild(input);
                n++;
            });
            if (n === 0) {
                ev.preventDefault();
                window.alert('Select at least one row to update.');
            }
        });
        var prefixFilter = document.getElementById('prefix_filter');
        var prefixMode = document.getElementById('prefix_mode');
        if (prefixFilter && prefixMode) {
            var paintPrefixMode = function () {
                var sample = String(prefixFilter.value || '').trim();
                var starts = prefixMode.querySelector('option[value="starts"]');
                var exact = prefixMode.querySelector('option[value="exact"]');
                if (starts) {
                    starts.textContent = sample === ''
                        ? 'Starts with'
                        : 'Starts with (' + sample + ' matches ' + sample + ', ' + sample + '1, ' + sample + '5)';
                }
                if (exact) {
                    exact.textContent = sample === ''
                        ? 'Exact code only'
                        : 'Exact code only (' + sample + ')';
                }
            };
            prefixFilter.addEventListener('input', paintPrefixMode);
            paintPrefixMode();
        }
    }

    var planSel = document.getElementById('f_rate_plan_id');
    var prefixSel = document.getElementById('f_plan_prefix_id');
    if (planSel && prefixSel) {
        var syncPlanPrefix = function () {
            var pid = String(planSel.value || '');
            Array.prototype.forEach.call(prefixSel.options, function (opt) {
                if (!opt.value) {
                    opt.hidden = false;
                    opt.disabled = false;
                    return;
                }
                var ok = opt.getAttribute('data-plan') === pid;
                opt.hidden = !ok;
                opt.disabled = !ok;
            });
            var selected = prefixSel.options[prefixSel.selectedIndex];
            if (!selected || selected.disabled) {
                prefixSel.value = '';
            }
        };
        planSel.addEventListener('change', syncPlanPrefix);
        syncPlanPrefix();
    }
})();
