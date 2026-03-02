(function () {
    const qs = (s) => document.querySelector(s);
    const el = (id) => document.getElementById(id);

    const root = qs(".bcav-layout");
    if (!root) return;

    const state = {
        serviceId: parseInt(root.getAttribute("data-service-id"), 10),
        days: [],
        selectedDate: null,
        windowsByDate: {}, // date -> [{time_from,time_to}]
    };

    function setHint(text, isError = false) {
        const h = el("bcav-hint");
        h.textContent = text || "";
        h.className = "bcav-hint" + (isError ? " is-error" : "");
    }

    function api(path, options = {}) {
        const url = `${BC_ADMIN_AV.restUrl}${path}`;
        const headers = options.headers || {};
        headers["X-WP-Nonce"] = BC_ADMIN_AV.nonce;
        headers["Content-Type"] = "application/json";
        return fetch(url, { ...options, headers }).then(async (r) => {
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw (data || { message: "Request failed" });
            return data;
        });
    }

    function monthRuShort(dateObj) {
        const arr = ["янв","фев","мар","апр","май","июн","июл","авг","сен","окт","ноя","дек"];
        return arr[dateObj.getMonth()] || "";
    }

    function renderPresets() {
        const wrap = el("bcav-presets");
        wrap.innerHTML = "";

        (BC_ADMIN_AV.presets || []).forEach((p) => {
            const b = document.createElement("button");
            b.type = "button";
            b.className = "bcav-preset";
            b.textContent = p.label || `${p.time_from}–${p.time_to}`;

            b.addEventListener("click", () => {
                if (!state.selectedDate) {
                    setHint("Сначала выберите дату", true);
                    return;
                }
                const list = state.windowsByDate[state.selectedDate] || [];
                const exists = list.some(
                    (x) => x.time_from === p.time_from && x.time_to === p.time_to
                );
                if (!exists) {
                    list.push({ time_from: p.time_from, time_to: p.time_to });
                    // сортируем по time_from
                    list.sort((a, b) => (a.time_from > b.time_from ? 1 : -1));
                    state.windowsByDate[state.selectedDate] = list;
                    renderWindows();
                    setHint("");
                }
            });

            wrap.appendChild(b);
        });
    }

    function renderDays() {
        const wrap = el("bcav-days");
        wrap.innerHTML = "";

        state.days.forEach((d) => {
            const dateObj = new Date(d.date + "T00:00:00");
            const day = String(dateObj.getDate()).padStart(2, "0");

            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "bcav-day";
            btn.dataset.date = d.date;

            // “открыта” если есть интервалы
            if (d.has_windows) btn.classList.add("is-open");
            if (state.selectedDate === d.date) btn.classList.add("is-active");

            btn.innerHTML = `<div class="d">${day}</div><div class="m">${monthRuShort(dateObj)}</div>`;

            btn.addEventListener("click", () => {
                state.selectedDate = d.date;
                el("bcav-picked-date").textContent = d.date;
                renderDays();
                renderWindows();
                setHint("");
            });

            wrap.appendChild(btn);
        });
    }

    function renderWindows() {
        const wrap = el("bcav-windows");
        wrap.innerHTML = "";

        if (!state.selectedDate) {
            wrap.innerHTML = `<div style="color:#64748b;font-size:12px;">Выберите дату слева</div>`;
            return;
        }

        const list = state.windowsByDate[state.selectedDate] || [];
        if (!list.length) {
            wrap.innerHTML = `<div style="color:#64748b;font-size:12px;">Интервалы не выбраны (дата будет закрыта)</div>`;
            return;
        }

        list.forEach((w, idx) => {
            const row = document.createElement("div");
            row.className = "bcav-window";
            row.innerHTML = `<b>${w.time_from}–${w.time_to}</b>`;

            const rm = document.createElement("button");
            rm.type = "button";
            rm.className = "button";
            rm.textContent = "Удалить";
            rm.addEventListener("click", () => {
                list.splice(idx, 1);
                state.windowsByDate[state.selectedDate] = list;
                renderWindows();
            });

            row.appendChild(rm);
            wrap.appendChild(row);
        });
    }

    async function load() {
        setHint("Загружаю...");
        const data = await api(`/admin/availability?service_id=${state.serviceId}`);
        state.days = data.days || [];

        state.windowsByDate = {};
        state.days.forEach((d) => {
            state.windowsByDate[d.date] = d.windows || [];
        });

        // авто-выбор первой даты (сегодня)
        state.selectedDate = state.days[0]?.date || null;
        el("bcav-picked-date").textContent = state.selectedDate || "—";

        renderPresets();
        renderDays();
        renderWindows();
        setHint("");
    }

    async function save() {
        if (!state.selectedDate) return;

        const windows = state.windowsByDate[state.selectedDate] || [];
        setHint("Сохраняю...");

        await api(`/admin/availability`, {
            method: "POST",
            body: JSON.stringify({
                service_id: state.serviceId,
                date: state.selectedDate,
                windows,
            }),
        });

        // обновим “has_windows” для раскраски квадрата
        const day = state.days.find((x) => x.date === state.selectedDate);
        if (day) day.has_windows = windows.length > 0;

        renderDays();
        setHint("Сохранено");
    }

    function clearDate() {
        if (!state.selectedDate) return;
        state.windowsByDate[state.selectedDate] = [];
        renderWindows();
        setHint("Интервалы очищены. Нажмите “Сохранить”, чтобы закрыть дату.");
    }

    el("bcav-save").addEventListener("click", () => save().catch((e) => setHint(e?.message || "Ошибка", true)));
    el("bcav-clear").addEventListener("click", clearDate);

    load().catch((e) => setHint(e?.message || "Ошибка загрузки", true));
})();