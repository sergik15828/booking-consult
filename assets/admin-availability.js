(function () {
    const roots = document.querySelectorAll(".bcav-layout");
    if (!roots.length) return;

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
        const arr = ["янв", "фев", "мар", "апр", "май", "июн", "июл", "авг", "сен", "окт", "ноя", "дек"];
        return arr[dateObj.getMonth()] || "";
    }

    function createAvailabilityApp(root) {
        const serviceId = parseInt(root.getAttribute("data-service-id"), 10);
        const state = {
            serviceId,
            days: [],
            selectedDate: null,
            windowsByDate: {},
            presets: BC_ADMIN_AV.servicePresets?.[serviceId] || [],
        };

        const elements = {
            days: root.querySelector('[data-role="days"]'),
            pickedDate: root.querySelector('[data-role="picked-date"]'),
            presets: root.querySelector('[data-role="presets"]'),
            windows: root.querySelector('[data-role="windows"]'),
            hint: root.querySelector('[data-role="hint"]'),
            clear: root.querySelector('[data-action="clear"]'),
            save: root.querySelector('[data-action="save"]'),
        };

        function setHint(text, isError = false) {
            elements.hint.textContent = text || "";
            elements.hint.className = "bcav-hint" + (isError ? " is-error" : "");
        }

        function renderPresets() {
            elements.presets.innerHTML = "";

            state.presets.forEach((preset) => {
                const button = document.createElement("button");
                button.type = "button";
                button.className = "bcav-preset";
                button.textContent = preset.label || `${preset.time_from}–${preset.time_to}`;

                button.addEventListener("click", () => {
                    if (!state.selectedDate) {
                        setHint("Сначала выберите дату", true);
                        return;
                    }

                    const list = state.windowsByDate[state.selectedDate] || [];
                    const exists = list.some(
                        (item) => item.time_from === preset.time_from && item.time_to === preset.time_to
                    );

                    if (!exists) {
                        list.push({ time_from: preset.time_from, time_to: preset.time_to });
                        list.sort((a, b) => (a.time_from > b.time_from ? 1 : -1));
                        state.windowsByDate[state.selectedDate] = list;
                        renderWindows();
                        setHint("");
                    }
                });

                elements.presets.appendChild(button);
            });
        }

        function renderDays() {
            elements.days.innerHTML = "";

            state.days.forEach((dayData) => {
                const dateObj = new Date(dayData.date + "T00:00:00");
                const day = String(dateObj.getDate()).padStart(2, "0");

                const button = document.createElement("button");
                button.type = "button";
                button.className = "bcav-day";
                button.dataset.date = dayData.date;

                if (dayData.has_windows) button.classList.add("is-open");
                if (state.selectedDate === dayData.date) button.classList.add("is-active");

                button.innerHTML = `<div class="d">${day}</div><div class="m">${monthRuShort(dateObj)}</div>`;

                button.addEventListener("click", () => {
                    state.selectedDate = dayData.date;
                    elements.pickedDate.textContent = dayData.date;
                    renderDays();
                    renderWindows();
                    setHint("");
                });

                elements.days.appendChild(button);
            });
        }

        function renderWindows() {
            elements.windows.innerHTML = "";

            if (!state.selectedDate) {
                elements.windows.innerHTML = '<div class="bcav-empty">Выберите дату слева</div>';
                return;
            }

            const list = state.windowsByDate[state.selectedDate] || [];
            if (!list.length) {
                elements.windows.innerHTML = '<div class="bcav-empty">Интервалы не выбраны (дата будет закрыта)</div>';
                return;
            }

            list.forEach((windowData, index) => {
                const row = document.createElement("div");
                row.className = "bcav-window";
                row.innerHTML = `<b>${windowData.time_from}–${windowData.time_to}</b>`;

                const removeButton = document.createElement("button");
                removeButton.type = "button";
                removeButton.className = "button";
                removeButton.textContent = "Удалить";
                removeButton.addEventListener("click", () => {
                    list.splice(index, 1);
                    state.windowsByDate[state.selectedDate] = list;
                    renderWindows();
                });

                row.appendChild(removeButton);
                elements.windows.appendChild(row);
            });
        }

        async function load() {
            setHint("Загружаю...");

            const data = await api(`/admin/availability?service_id=${state.serviceId}`);
            state.days = data.days || [];
            state.windowsByDate = {};

            state.days.forEach((dayData) => {
                state.windowsByDate[dayData.date] = dayData.windows || [];
            });

            state.selectedDate = state.days[0]?.date || null;
            elements.pickedDate.textContent = state.selectedDate || "—";

            renderPresets();
            renderDays();
            renderWindows();
            setHint("");
        }

        async function save() {
            if (!state.selectedDate) return;

            const windows = state.windowsByDate[state.selectedDate] || [];
            setHint("Сохраняю...");

            await api("/admin/availability", {
                method: "POST",
                body: JSON.stringify({
                    service_id: state.serviceId,
                    date: state.selectedDate,
                    windows,
                }),
            });

            const day = state.days.find((item) => item.date === state.selectedDate);
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

        elements.save.addEventListener("click", () => save().catch((e) => setHint(e?.message || "Ошибка", true)));
        elements.clear.addEventListener("click", clearDate);

        load().catch((e) => setHint(e?.message || "Ошибка загрузки", true));
    }

    roots.forEach(createAvailabilityApp);
})();
