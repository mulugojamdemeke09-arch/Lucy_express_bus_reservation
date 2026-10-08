/* ==========================================================================
 * LUCY EXPRESS // ሉሲ ኤክስፕሬስ — Bus Reservation System
 * js/app.js — pure vanilla JavaScript. No frameworks, no dependencies.
 *
 * Modules (each self-initialising only when its markup is present):
 *   1. toasts               — small helper used by every module
 *   2. station swap         — DOM manipulation on the two search selects
 *   3. date guards          — block past dates on <input type="date">
 *   4. seat selector grid   — click state, live pricing, checkout panel
 *   5. live seat sync       — fetch() polling of occupied seats
 *   6. checkout submit      — async race-condition-safe booking POST
 *   7. admin console        — expandable capacity rows + mini seat maps
 *   8. misc                 — print button, progress bars
 *
 * Server-injected runtime config lives on window.LUCY (see each PHP page).
 * ========================================================================== */
(function () {
    'use strict';

    var CFG = window.LUCY || {};
    var API = CFG.api || {};

    /* ------------------------------------------------------------ helpers -- */
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function todayISO() {
        var d = new Date();
        var off = d.getTimezoneOffset();
        return new Date(d.getTime() - off * 60000).toISOString().slice(0, 10);
    }
    function money(n) {
        return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (CFG.currency || 'ETB');
    }
    function postJSON(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false, message: 'Malformed server response.' }; })
                .then(function (data) { return { status: res.status, data: data }; });
        });
    }

    /* ------------------------------------------------------------- 1. toast -- */
    function toast(message, variant) {
        var stack = $('.toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            document.body.appendChild(stack);
        }
        var el = document.createElement('div');
        el.className = 'toast' + (variant ? ' toast--' + variant : '');
        el.setAttribute('role', 'status');
        el.textContent = message;
        stack.appendChild(el);
        window.setTimeout(function () {
            el.classList.add('toast--out');
            window.setTimeout(function () { el.remove(); }, 260);
        }, 4200);
    }

    /* --------------------------------------------------- 2. station swapping -- */
    function initStationSwap() {
        var form = $('#search-form');
        if (!form) { return; }
        var from = $('#source', form);
        var to = $('#destination', form);
        var swap = $('#swap-stations', form);
        if (!from || !to || !swap) { return; }

        swap.addEventListener('click', function () {
            var a = from.value;
            var b = to.value;
            from.value = b;
            to.value = a;

            [from, to].forEach(function (field) {
                field.classList.add('is-swapped');
                window.setTimeout(function () { field.classList.remove('is-swapped'); }, 380);
            });
            toast('Stations swapped: ' + (to.value || '—') + ' ⇄ ' + (from.value || '—'), 'ok');
        });
    }

    /* ------------------------------------------------------- 3. date guards -- */
    function initDateGuards() {
        var min = todayISO();
        $$('input[type="date"]').forEach(function (input) {
            if (input.dataset.allowPast === 'true') { return; }
            input.min = min;
            if (!input.value) { input.value = input.dataset.defaultToday === 'true' ? min : ''; }
            input.addEventListener('change', function () {
                if (input.value && input.value < min) {
                    input.value = min;
                    toast('Travel date cannot be in the past — moved to today.', 'error');
                }
            });
        });
    }

    /* ------------------------------------------------------ 4/5/6. booking -- */
    function initSeatSelector() {
        var map = $('#seat-map');
        if (!map || !CFG.tripId) { return; }

        var panel = $('#checkout-panel');
        var hint = $('#checkout-hint');
        var chips = $('#selected-chips');
        var lineSeats = $('#line-seats');
        var lineCount = $('#line-count');
        var totalEl = $('#checkout-total-value');
        var confirmBtn = $('#confirm-booking');
        var nameInput = $('#passenger-name');
        var phoneInput = $('#passenger-phone');
        var feedback = $('#checkout-feedback');

        var fare = Number(CFG.fare || 0);
        var selected = [];

        function seatButton(seatNumber) {
            return map.querySelector('.seat[data-seat="' + seatNumber + '"]');
        }

        function render() {
            selected.sort(function (a, b) { return a - b; });

            // seat chips
            chips.innerHTML = '';
            selected.forEach(function (seat) {
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'seat-chip';
                chip.textContent = 'Seat ' + seat + ' ✕';
                chip.setAttribute('aria-label', 'Deselect seat ' + seat);
                chip.addEventListener('click', function () { toggleSeat(seat); });
                chips.appendChild(chip);
            });

            // live totals
            var count = selected.length;
            lineSeats.textContent = count ? 'Seat' + (count > 1 ? 's' : '') + ' ' + selected.join(', ') : '—';
            lineCount.textContent = count + ' × ' + money(fare);
            totalEl.textContent = money(count * fare);

            // checkout panel appears only once at least one seat is active
            if (count > 0) {
                panel.hidden = false;
                hint.hidden = true;
            } else {
                panel.hidden = true;
                hint.hidden = false;
            }
            confirmBtn.disabled = count === 0;
        }

        function toggleSeat(seatNumber) {
            var btn = seatButton(seatNumber);
            if (!btn || btn.disabled || btn.classList.contains('seat--booked')) { return; }

            if (btn.classList.contains('seat--selected')) {
                btn.classList.remove('seat--selected');
                btn.classList.add('seat--available');
                selected = selected.filter(function (n) { return n !== seatNumber; });
            } else {
                if (selected.length >= 6) {
                    toast('A single guest transaction is capped at 6 seats — please book twice for larger groups.', 'error');
                    return;
                }
                btn.classList.remove('seat--available');
                btn.classList.add('seat--selected');
                selected.push(seatNumber);
            }
            render();
        }

        map.addEventListener('click', function (event) {
            var btn = event.target.closest('.seat');
            if (!btn || !btn.dataset.seat) { return; }
            toggleSeat(parseInt(btn.dataset.seat, 10));
        });

        /* Keyboard accessibility: the seats are real <button> elements, so
           Enter / Space already dispatch a native click — no extra handler is
           needed here (adding one would double-toggle the seat state). */

        /* ---- 5. asynchronous seat-state sync (also closes race windows) ---- */
        function syncBookedSeats(silent) {
            if (!API.booked) { return; }
            fetch(API.booked + '?trip_id=' + encodeURIComponent(CFG.tripId), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) { return; }
                    var dropped = [];
                    (data.booked || []).forEach(function (seatNumber) {
                        var btn = seatButton(seatNumber);
                        if (!btn || btn.classList.contains('seat--booked')) { return; }
                        btn.classList.remove('seat--available', 'seat--selected');
                        btn.classList.add('seat--booked');
                        btn.disabled = true;
                        btn.setAttribute('aria-disabled', 'true');
                        if (selected.indexOf(seatNumber) !== -1) {
                            selected = selected.filter(function (n) { return n !== seatNumber; });
                            dropped.push(seatNumber);
                        }
                    });
                    if (dropped.length) {
                        render();
                        toast('Seat(s) ' + dropped.join(', ') + ' were just taken by another passenger and have been released from your selection.', 'error');
                    } else if (!silent) {
                        toast('Seat map refreshed.', 'ok');
                    }
                })
                .catch(function () { /* offline sync is non-fatal */ });
        }

        /* refresh button inside the map card */
        var refresh = $('#refresh-seats');
        if (refresh) {
            refresh.addEventListener('click', function () { syncBookedSeats(false); });
        }
        if (CFG.poll !== false) {
            window.setInterval(function () { syncBookedSeats(true); }, 15000);
        }

        /* ---- 6. finalise the booking through the API ---- */
        function phoneLooksValid(value) {
            var cleaned = value.replace(/[\s\-()]/g, '');
            if (/^(?:\+251|251|0)?9\d{8}$/.test(cleaned)) { return true; }   // Ethiopian mobile
            return /^\+?\d{9,15}$/.test(cleaned);                            // generic international
        }

        confirmBtn.addEventListener('click', function () {
            if (!selected.length) { return; }

            var name = (nameInput.value || '').trim();
            var phone = (phoneInput.value || '').trim();
            feedback.innerHTML = '';
            nameInput.parentElement.classList.toggle('field--error', name.length < 3);
            phoneInput.parentElement.classList.toggle('field--error', !phoneLooksValid(phone));

            if (name.length < 3) {
                feedback.innerHTML = '<div class="notice notice--error">Please enter the passenger full name (3+ characters).</div>';
                nameInput.focus();
                return;
            }
            if (!phoneLooksValid(phone)) {
                feedback.innerHTML = '<div class="notice notice--error">Please enter a valid mobile number, e.g. 0912345678 or +251912345678.</div>';
                phoneInput.focus();
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Locking seats…';
            feedback.innerHTML = '<div class="notice">Running transactional seat check — please hold.</div>';

            postJSON(API.finalize, {
                trip_id: CFG.tripId,
                seats: selected,
                passenger_name: name,
                passenger_phone: phone
            }).then(function (res) {
                var data = res.data || {};

                if (res.status === 409 && data.conflicts) {
                    // somebody won the race for these seats
                    data.conflicts.forEach(function (seatNumber) {
                        var btn = seatButton(seatNumber);
                        if (!btn) { return; }
                        btn.classList.remove('seat--selected', 'seat--available');
                        btn.classList.add('seat--booked');
                        btn.disabled = true;
                    });
                    selected = selected.filter(function (n) { return data.conflicts.indexOf(n) === -1; });
                    render();
                    feedback.innerHTML = '<div class="notice notice--error">Seat(s) <strong>' + data.conflicts.join(', ') + '</strong> were reserved by another passenger a split second earlier. They have been removed — please pick different seats.</div>';
                    confirmBtn.disabled = selected.length === 0;
                    confirmBtn.textContent = 'Confirm Ticket Allocation';
                    toast('Double booking blocked. Choose another seat.', 'error');
                    return;
                }

                if (!data.ok) {
                    feedback.innerHTML = '<div class="notice notice--error">' + (data.message || 'The reservation could not be completed.') + '</div>';
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Confirm Ticket Allocation';
                    return;
                }

                feedback.innerHTML = '<div class="notice notice--ok">Confirmed! Printing your ticket…</div>';
                confirmBtn.textContent = 'Redirecting…';
                window.location.href = (CFG.successUrl || 'ticket-success.php') + '?code=' + encodeURIComponent(data.confirmation_code);
            }).catch(function () {
                feedback.innerHTML = '<div class="notice notice--error">Network error while contacting the reservation engine. Check that Apache/MySQL are still running and try again.</div>';
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Confirm Ticket Allocation';
            });
        });

        render();
    }

    /* ------------------------------------------------------- 7. admin console -- */
    function buildMiniMap(container, totalSeats, booked) {
        container.innerHTML = '';
        var taken = booked || [];
        var n = 1;
        var rows = Math.ceil(totalSeats / 4);

        for (var r = 0; r < rows; r++) {
            var row = document.createElement('div');
            row.className = 'mini-row';
            for (var i = 0; i < 4; i++) {
                if (i === 2) {
                    var gap = document.createElement('span');
                    gap.className = 'mini-seat mini-seat--gap';
                    row.appendChild(gap);
                }
                var seat = document.createElement('span');
                if (n <= totalSeats) {
                    seat.className = 'mini-seat' + (taken.indexOf(n) !== -1 ? ' mini-seat--booked' : '');
                    seat.textContent = n;
                    seat.title = 'Seat ' + n + (taken.indexOf(n) !== -1 ? ' — booked' : ' — available');
                    n++;
                } else {
                    seat.className = 'mini-seat mini-seat--gap';
                }
                row.appendChild(seat);
            }
            container.appendChild(row);
        }
    }

    function initAdminConsole() {
        var table = $('#capacity-table');
        if (!table) { return; }

        table.addEventListener('click', function (event) {
            var row = event.target.closest('.trip-row');
            if (!row) { return; }
            if (event.target.closest('form') || event.target.closest('button[type="submit"]')) { return; }

            var detail = row.nextElementSibling;
            if (!detail || !detail.classList.contains('expander')) { return; }

            var isOpen = row.classList.contains('is-open');
            if (isOpen) {
                detail.hidden = true;
                row.classList.remove('is-open');
                return;
            }

            var holder = detail.querySelector('.mini-map');
            if (holder && !holder.dataset.rendered) {
                var booked = [];
                try { booked = JSON.parse(row.dataset.booked || '[]'); } catch (e) { booked = []; }
                buildMiniMap(holder, parseInt(row.dataset.totalSeats || '0', 10), booked);
                holder.dataset.rendered = '1';

                var summary = detail.querySelector('.expander__summary');
                if (summary) {
                    summary.textContent = booked.length
                        ? 'Occupied seats: ' + booked.join(', ')
                        : 'No seats sold yet — the whole cabin is open.';
                }
            }
            detail.hidden = false;
            row.classList.add('is-open');
        });
    }

    /* ------------------------------------------------------------- 8. misc -- */
    function initMisc() {
        var printBtn = $('#print-ticket');
        if (printBtn) {
            printBtn.addEventListener('click', function () { window.print(); });
        }

        // live occupancy bar for the trip creator preview (purely cosmetic)
        var priced = $('#ticket_price');
        var bus = $('#bus_id');
        var preview = $('#trip-preview');
        if (priced && bus && preview) {
            var update = function () {
                preview.textContent = 'New schedule will open ' + (bus.options[bus.selectedIndex] ? bus.options[bus.selectedIndex].textContent : 'a bus') +
                    ' at ' + (priced.value ? money(priced.value) : '0.00 ETB') + ' per seat.';
            };
            priced.addEventListener('input', update);
            bus.addEventListener('change', update);
            update();
        }
    }

    /* ------------------------------------------------------------- bootstrap -- */
    document.addEventListener('DOMContentLoaded', function () {
        initStationSwap();
        initDateGuards();
        initSeatSelector();
        initAdminConsole();
        initMisc();
    });
})();
