/**
 * gigio-submit.js — front-end for the [gigiau_submit] page.
 *
 * Event organizers (who are NOT WordPress users) sign in with a simple email +
 * password, submit events (poster, title, date/time, venue), and view/edit the
 * events they've submitted. Everything talks to the custom /gigiau/v1/organizer
 * REST endpoints. Submitted events are held for admin approval before they show
 * on the public listing.
 */
(function () {
    "use strict";

    var root, state = { csrf: "", venues: [], events: [], organizer: null, editingId: null };

    document.addEventListener("DOMContentLoaded", init);
    // In case the script loads after DOMContentLoaded (footer + async themes):
    if (document.readyState !== "loading") init();

    var started = false;
    function init() {
        if (started) return;
        root = document.querySelector(".gigio-submit");
        if (!root) return;
        started = true;

        var config = {};
        try { config = JSON.parse(root.getAttribute("data-config") || "{}"); } catch (e) {}
        state.restBase = (config.restBase || "").replace(/\/$/, "");
        state.venues = config.venues || [];

        refreshSession();
    }

    // ---------- helpers ----------

    function el(sel) { return root.querySelector(sel); }
    function body() { return root.querySelector(".gigio-submit-body"); }

    function esc(s) {
        return ("" + (s == null ? "" : s))
            .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;").replaceAll("'", "&#39;");
    }

    function setStatus(msg, type) {
        var s = root.querySelector(".gigio-submit-status");
        s.textContent = msg || "";
        s.className = "gigio-submit-status" + (msg ? " " + (type || "") : "");
    }

    /**
     * Call a REST endpoint. `data` may be a plain object (sent as JSON) or a
     * FormData (sent as multipart, e.g. with a poster file). Write requests
     * carry the CSRF token. Resolves with parsed JSON, rejects with an Error
     * whose message is human-readable.
     */
    function api(path, method, data) {
        var opts = { method: method || "GET", credentials: "same-origin", headers: {} };
        if (state.csrf) opts.headers["X-Gigio-Csrf"] = state.csrf;
        if (data instanceof FormData) {
            opts.body = data;
        } else if (data) {
            opts.headers["Content-Type"] = "application/json";
            opts.body = JSON.stringify(data);
        }
        return fetch(state.restBase + path, opts).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok) {
                    throw new Error((j && j.message) || ("Request failed (" + r.status + ")"));
                }
                return j;
            });
        });
    }

    function applyPayload(p) {
        if (p && p.csrf !== undefined) state.csrf = p.csrf || "";
        if (p && p.organizer) state.organizer = p.organizer;
        if (p && p.events) state.events = p.events;
        if (p && p.venues) state.venues = p.venues;
    }

    function toDatetimeLocal(v) {
        if (!v) return "";
        var s = ("" + v).replace(" ", "T").substring(0, 16);
        if (s.length === 10) s += "T19:00"; // date only -> give it a default time
        return s;
    }
    function toDateOnly(v) { return v ? ("" + v).substring(0, 10) : ""; }

    function todayLocalDate() {
        var d = new Date();
        return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().substring(0, 10);
    }

    /**
     * Sensible checks on the organizer-supplied dates, mirroring the server.
     * A past start is allowed as long as the end date is today or later (a
     * still-running event). Returns an error message, or null if fine. Compares
     * by date only ("YYYY-MM-DD" sorts correctly as a string).
     */
    function validateDates(startVal, endVal) {
        if (!startVal) return "Please choose a date and time for the event.";
        var today = todayLocalDate();
        var startDay = ("" + startVal).substring(0, 10);
        var endDay = endVal ? ("" + endVal).substring(0, 10) : "";
        if (endDay && endDay < startDay) return "The end date can’t be before the start date.";
        if (startDay < today && (!endDay || endDay < today)) {
            return "That start date has passed. If the event is still running, set an end date of today or later; otherwise choose a current date.";
        }
        return null;
    }

    /**
     * Live hint: gently outline the start field in red when it's a past date
     * that isn't "rescued" by an end date of today or later. Does not block
     * entry — the hard check runs on Submit.
     */
    function updateDateHints() {
        var form = el(".gigio-event-form");
        if (!form) return;
        var today = todayLocalDate();
        var startDay = (form.dtstart.value || "").substring(0, 10);
        var endDay = (form.dtend.value || "").substring(0, 10);
        var oldStart = startDay && startDay < today && (!endDay || endDay < today);
        form.dtstart.classList.toggle("gigio-old-date", !!oldStart);
    }

    function friendly(v) {
        if (!v) return "";
        var d = new Date(("" + v).replace(" ", "T"));
        if (isNaN(d)) return v;
        var hasTime = /\d{1,2}:\d{2}/.test(v);
        var opts = { weekday: "short", day: "numeric", month: "short", year: "numeric" };
        if (hasTime) { opts.hour = "2-digit"; opts.minute = "2-digit"; }
        return d.toLocaleDateString(undefined, opts);
    }

    // ---------- session ----------

    function refreshSession() {
        body().textContent = "Loading…";
        api("/organizer/session", "GET").then(function (p) {
            if (p && p.venues) state.venues = p.venues;
            if (p && p.signedIn) {
                applyPayload(p);
                renderSignedIn();
            } else {
                state.csrf = "";
                state.organizer = null;
                renderAuth("login");
            }
        }).catch(function () {
            renderAuth("login");
        });
    }

    // ---------- auth (sign in / sign up) ----------

    function renderAuth(mode) {
        var isLogin = mode !== "register";
        body().innerHTML =
            '<h2>' + (isLogin ? "Sign in to submit an event" : "Create an organizer account") + '</h2>' +
            '<form class="gigio-auth-form">' +
            (isLogin ? "" :
                '<label class="gigio-field"><span>Your name</span>' +
                '<input type="text" name="name" autocomplete="name" /></label>') +
            '<label class="gigio-field"><span>Email</span>' +
            '<input type="email" name="email" required autocomplete="email" /></label>' +
            '<label class="gigio-field"><span>Password</span>' +
            '<input type="password" name="password" required autocomplete="' +
            (isLogin ? "current-password" : "new-password") + '" minlength="8" /></label>' +
            '<button type="submit">' + (isLogin ? "Sign in" : "Create account") + '</button>' +
            '</form>' +
            '<p>' + (isLogin ? "New here? " : "Already have an account? ") +
            '<button type="button" class="gigio-secondary gigio-toggle-auth">' +
            (isLogin ? "Create an account" : "Sign in") + '</button></p>';

        el(".gigio-toggle-auth").addEventListener("click", function () {
            setStatus("");
            renderAuth(isLogin ? "register" : "login");
        });

        el(".gigio-auth-form").addEventListener("submit", function (e) {
            e.preventDefault();
            var f = e.target;
            var payload = {
                email: f.email.value.trim(),
                password: f.password.value
            };
            if (!isLogin) payload.name = f.name.value.trim();
            setStatus(isLogin ? "Signing in…" : "Creating account…");
            api("/organizer/" + (isLogin ? "login" : "register"), "POST", payload)
                .then(function (p) {
                    applyPayload(p);
                    setStatus("");
                    renderSignedIn();
                })
                .catch(function (err) { setStatus(err.message, "error"); });
        });
    }

    // ---------- signed-in: submit form + my events ----------

    function renderSignedIn() {
        var who = state.organizer ? (state.organizer.name || state.organizer.email) : "";
        body().innerHTML =
            '<div class="gigio-submit-topbar">' +
            '<span class="gigio-who">Signed in as ' + esc(who) + '</span>' +
            '<button type="button" class="gigio-secondary gigio-logout">Sign out</button>' +
            '</div>' +
            '<h2 class="gigio-form-heading">Submit an event</h2>' +
            '<form class="gigio-event-form">' +
            '<label class="gigio-field"><span>Event title</span>' +
            '<input type="text" name="title" required /></label>' +
            '<label class="gigio-field"><span>Date &amp; time</span>' +
            '<input type="datetime-local" name="dtstart" required /></label>' +
            '<label class="gigio-field"><span>End date (only if it runs over several days)</span>' +
            '<input type="date" name="dtend" /></label>' +
            '<label class="gigio-field"><span>Extra info (optional, max 80 characters)</span>' +
            '<input type="text" name="dtinfo" maxlength="80" placeholder="e.g. finish 10pm. £5 on the door" /></label>' +
            '<label class="gigio-field"><span>Venue</span>' +
            buildVenueInput("") +
            '</label>' +
            '<label class="gigio-field"><span>More info / booking link (optional)</span>' +
            '<input type="url" name="bookinglink" placeholder="https://…" /></label>' +
            '<label class="gigio-field"><span class="gigio-poster-label">Poster image</span>' +
            '<input type="file" name="picture" accept="image/*" required /></label>' +
            '<div class="gigio-form-actions">' +
            '<button type="submit" class="gigio-submit-btn">Submit for approval</button>' +
            '<button type="button" class="gigio-secondary gigio-cancel-edit" style="display:none">Cancel edit</button>' +
            '</div>' +
            '</form>' +
            '<h2>Your events</h2>' +
            '<ul class="gigio-my-events"></ul>';

        el(".gigio-logout").addEventListener("click", function () {
            api("/organizer/logout", "POST").then(function () {
                state.csrf = ""; state.organizer = null; state.events = [];
                setStatus("");
                renderAuth("login");
            });
        });

        var form = el(".gigio-event-form");
        el(".gigio-cancel-edit").addEventListener("click", function () { resetForm(); });

        // Gently flag an old start date (red outline) so the organizer knows to
        // add an end date of today or later. This does NOT block entry — they can
        // set the old start first and the real check runs on Submit.
        form.dtstart.addEventListener("input", updateDateHints);
        form.dtend.addEventListener("input", updateDateHints);

        form.addEventListener("submit", onSubmitEvent);

        renderMyEvents();
    }

    // Autocomplete text input backed by a datalist of known venues: the organizer
    // types, gets matching suggestions to complete with Tab/Enter, or just types
    // a new name. No forced selection from a list.
    function buildVenueInput(selected) {
        var options = state.venues.map(function (v) {
            return '<option value="' + esc(v) + '"></option>';
        }).join("");
        return '<input type="text" name="venue" list="gigio-venue-list" autocomplete="off"' +
            ' placeholder="Start typing to find a venue, or type a new name"' +
            ' value="' + esc(selected || "") + '" />' +
            '<datalist id="gigio-venue-list">' + options + '</datalist>';
    }

    function onSubmitEvent(e) {
        e.preventDefault();
        var form = e.target;

        var dateError = validateDates(form.dtstart.value, form.dtend.value);
        if (dateError) {
            setStatus(dateError, "error");
            form.dtstart.focus();
            return;
        }

        var fd = new FormData();
        fd.append("title", form.title.value.trim());
        fd.append("dtstart", form.dtstart.value);
        fd.append("dtend", form.dtend.value);
        fd.append("venue", form.venue.value.trim());
        fd.append("dtinfo", form.dtinfo.value.trim().substring(0, 80));
        fd.append("bookinglink", form.bookinglink.value.trim());
        if (form.picture.files && form.picture.files[0]) {
            fd.append("picture", form.picture.files[0]);
        }

        var editing = state.editingId;
        var path = editing ? "/organizer/events/" + editing : "/organizer/events";
        var btn = form.querySelector(".gigio-submit-btn");
        btn.disabled = true;
        setStatus(editing ? "Saving changes…" : "Submitting…");

        api(path, "POST", fd).then(function () {
            setStatus(editing
                ? "Saved. Your edited event will be reviewed again before it shows publicly."
                : "Thanks! Your event has been submitted and will appear once an admin approves it.",
                "success");
            resetForm();
            return refreshAfterWrite();
        }).catch(function (err) {
            setStatus(err.message, "error");
            btn.disabled = false;
        });
    }

    function refreshAfterWrite() {
        return api("/organizer/session", "GET").then(function (p) {
            if (p && p.signedIn) { applyPayload(p); renderMyEvents(); }
        });
    }

    function resetForm() {
        state.editingId = null;
        var form = el(".gigio-event-form");
        if (!form) return;
        form.reset();
        form.dtstart.classList.remove("gigio-old-date");
        el(".gigio-form-heading").textContent = "Submit an event";
        form.querySelector(".gigio-submit-btn").textContent = "Submit for approval";
        form.querySelector(".gigio-submit-btn").disabled = false;
        el(".gigio-cancel-edit").style.display = "none";
        form.querySelector('input[name="picture"]').required = true;
        el(".gigio-poster-label").textContent = "Poster image";
    }

    function renderMyEvents() {
        var ul = el(".gigio-my-events");
        if (!ul) return;
        if (!state.events.length) {
            ul.innerHTML = '<li class="gigio-my-event-empty">You haven’t submitted any events yet.</li>';
            return;
        }
        ul.innerHTML = state.events.map(function (ev) {
            var badge = ev.approved
                ? '<span class="gigio-status-badge approved">Approved</span>'
                : '<span class="gigio-status-badge pending">Awaiting approval</span>';
            var meta = [friendly(ev.dtstart), ev.venue].filter(Boolean).map(esc).join(" · ");
            return '<li class="gigio-my-event" data-id="' + ev.id + '">' +
                (ev.picture ? '<img src="' + esc(ev.picture) + '" alt="" />' : '<img alt="" />') +
                '<div class="gigio-my-event-main">' +
                '<div class="gigio-my-event-title">' + esc(ev.title) + '</div>' +
                '<div class="gigio-my-event-meta">' + meta + '</div>' +
                badge +
                '</div>' +
                '<button type="button" class="gigio-secondary gigio-edit-event">Edit</button>' +
                '</li>';
        }).join("");

        ul.querySelectorAll(".gigio-edit-event").forEach(function (b) {
            b.addEventListener("click", function () {
                var id = b.closest(".gigio-my-event").getAttribute("data-id");
                startEdit(id);
            });
        });
    }

    function startEdit(id) {
        var ev = state.events.filter(function (e) { return "" + e.id === "" + id; })[0];
        if (!ev) return;
        state.editingId = ev.id;

        var form = el(".gigio-event-form");
        form.title.value = ev.title || "";
        form.dtstart.value = toDatetimeLocal(ev.dtstart);
        form.dtend.value = toDateOnly(ev.dtend) === toDateOnly(ev.dtstart) ? "" : toDateOnly(ev.dtend);
        form.dtinfo.value = ev.dtinfo || "";
        form.bookinglink.value = ev.bookinglink || "";
        form.venue.value = ev.venue || "";

        // On edit the existing poster is kept unless a new one is chosen.
        var picture = form.querySelector('input[name="picture"]');
        picture.required = false;
        picture.value = "";
        el(".gigio-poster-label").textContent = "Replace poster image (optional)";

        el(".gigio-form-heading").textContent = "Edit event";
        form.querySelector(".gigio-submit-btn").textContent = "Save changes";
        el(".gigio-cancel-edit").style.display = "";

        updateDateHints();
        setStatus("Editing “" + (ev.title || "") + "”. Saving will send it back for approval.");
        form.scrollIntoView({ behavior: "smooth", block: "start" });
    }
})();
