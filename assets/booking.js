(function () {
	const el = (id) => document.getElementById(id);

	const state = {
		serviceId: null,
		days: [],
		selectedDate: null,
		selectedSlot: null,
	};

	function api(path, options = {}) {
		const url = `${BC_BOOKING.restUrl}${path}`;
		const headers = options.headers || {};
		headers["X-WP-Nonce"] = BC_BOOKING.nonce;
		headers["Content-Type"] = "application/json";
		return fetch(url, { ...options, headers }).then(async (r) => {
			const data = await r.json().catch(() => ({}));
			if (!r.ok) throw (data || { message: "Request failed" });
			return data;
		});
	}

	function renderDays(days) {
		const wrap = el("bc-days");
		wrap.innerHTML = "";

		// показываем только ближайшие ~14 дней визуально, остальное скролл (как на скрине)
		// но данные все на 30 дней
		days.forEach((d) => {
			const dateObj = new Date(d.date + "T00:00:00");
			const day = String(dateObj.getDate()).padStart(2, "0");

			const btn = document.createElement("button");
			btn.type = "button";
			btn.className = "bc-day";
			btn.dataset.date = d.date;

			if (!d.has_slots) btn.classList.add("is-disabled");
			if (state.selectedDate === d.date) btn.classList.add("is-active");

			btn.innerHTML = `<div class="bc-day-top">${day}</div><div class="bc-day-bottom">${monthRuShort(dateObj)}</div>`;

			btn.addEventListener("click", () => {
				if (!d.has_slots) return;
				state.selectedDate = d.date;
				state.selectedSlot = null;
				el("bc-book").disabled = true;
				renderDays(state.days);
				loadSlots();
			});

			wrap.appendChild(btn);
		});
	}

	function monthRuShort(dateObj) {
		const m = dateObj.getMonth();
		const arr = ["янв","фев","мар","апр","май","июн","июл","авг","сен","окт","ноя","дек"];
		return arr[m] || "";
	}

	function renderSlots(slots) {
		const wrap = el("bc-slots");
		wrap.innerHTML = "";

		if (!state.selectedDate) {
			wrap.innerHTML = `<div class="bc-empty">Выберите дату</div>`;
			return;
		}

		if (!slots.length) {
			wrap.innerHTML = `<div class="bc-empty">Нет доступных слотов</div>`;
			return;
		}

		slots.forEach((s) => {
			const b = document.createElement("button");
			b.type = "button";
			b.className = "bc-slot";
			b.textContent = s.label;

			if (state.selectedSlot && state.selectedSlot.starts_at === s.starts_at) {
				b.classList.add("is-active");
			}

			b.addEventListener("click", () => {
				state.selectedSlot = s;
				el("bc-book").disabled = !BC_BOOKING.isLoggedIn;
				renderSlots(slots);
				setHint(BC_BOOKING.isLoggedIn ? "" : `Нужно войти: ${BC_BOOKING.loginUrl}`);
			});

			wrap.appendChild(b);
		});
	}

	function setHint(text, isError = false) {
		const hint = el("bc-hint");
		hint.textContent = text || "";
		hint.className = "bc-hint" + (isError ? " is-error" : "");
	}

	async function loadCalendar() {
		state.selectedDate = null;
		state.selectedSlot = null;
		el("bc-book").disabled = true;
		renderSlots([]);

		const data = await api(`/calendar?service_id=${state.serviceId}`);
		state.days = data.days || [];

		// авто-выбор первой доступной даты
		const first = state.days.find((d) => d.has_slots);
		state.selectedDate = first ? first.date : null;

		renderDays(state.days);
		await loadSlots();
	}

	async function loadSlots() {
		if (!state.selectedDate) {
			renderSlots([]);
			return;
		}
		const data = await api(`/slots?service_id=${state.serviceId}&date=${state.selectedDate}`);
		renderSlots(data.slots || []);
	}

	async function book() {
		if (!BC_BOOKING.isLoggedIn) {
			window.location.href = BC_BOOKING.loginUrl;
			return;
		}
		if (!state.selectedSlot) return;

		const payload = {
			service_id: parseInt(state.serviceId, 10),
			starts_at: state.selectedSlot.starts_at,
			ends_at: state.selectedSlot.ends_at,
			customer_name: el("bc-name").value.trim(),
			customer_email: el("bc-email").value.trim(),
			customer_phone: el("bc-phone").value.trim(),
			notes: el("bc-notes").value.trim(),
		};

		if (!payload.customer_name) {
			setHint("Заполните поле Имя", true);
			return;
		}

		el("bc-book").disabled = true;
		setHint("Сохраняю...");

		try {
			const res = await api(`/book`, {
				method: "POST",
				body: JSON.stringify(payload),
			});

			setHint("Готово! Запись создана.");
			// обновляем календарь/слоты
			await loadCalendar();

		} catch (e) {
			const msg = e?.message || e?.data?.message || "Ошибка. Попробуйте ещё раз.";
			setHint(msg, true);
			el("bc-book").disabled = false;
		}
	}

	function init() {
		const service = el("bc-service");
		if (!service) return;

		state.serviceId = service.value;

		service.addEventListener("change", async () => {
			state.serviceId = service.value;
			setHint("");
			await loadCalendar();
		});

		el("bc-book").addEventListener("click", book);

		loadCalendar().catch((e) => setHint(e?.message || "Ошибка загрузки", true));
	}

	document.addEventListener("DOMContentLoaded", init);
})();
