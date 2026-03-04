<?php
if (!defined('ABSPATH')) exit;

class BC_Availability {

	private static function wp_tz() {
		$tz = wp_timezone();
		return $tz ?: new DateTimeZone('UTC');
	}

	private static function weekday_1_to_7(DateTime $dt) {
		// PHP N: 1 (Mon) .. 7 (Sun)
		return (int) $dt->format('N');
	}

	public static function month_range_from_today() {
		$tz = self::wp_tz();
		$from = new DateTime('now', $tz);
		$from->setTime(0,0,0);

		$to = clone $from;
		$to->modify('+30 days');
		$to->setTime(23,59,59);

		return [$from, $to];
	}

	/**
	 * Возвращает список дат (Y-m-d) с флагом has_slots
	 */
	public static function calendar_overview($service_id) {
		[$from, $to] = self::month_range_from_today();

		$days = [];
		$cursor = clone $from;

		while ($cursor <= $to) {
			$date = $cursor->format('Y-m-d');
			$slots = self::slots_for_date($service_id, $date);
			$days[] = [
				'date' => $date,
				'has_slots' => count($slots) > 0,
			];
			$cursor->modify('+1 day');
		}

		return $days;
	}

	/**
	 * Возвращает свободные слоты для даты (Y-m-d)
	 * Слот: starts_at, ends_at, label ("11:00–12:30")
	 */
	public static function slots_for_date($service_id, $date_ymd) {
		$service = BC_DB::get_service($service_id);
		if (!$service || (int)$service['active'] !== 1) return [];

		$tz = self::wp_tz();
		$day = DateTime::createFromFormat('Y-m-d H:i:s', $date_ymd . ' 00:00:00', $tz);
		if (!$day) return [];

		// Не даём прошлые даты
		$today = new DateTime('now', $tz);
		$today->setTime(0,0,0);
		if ($day < $today) return [];

		// Строгая логика: доступны только интервалы, явно выбранные админом на эту дату.
		$windows = BC_DB::get_availability_windows($service_id, $date_ymd);
		if (!$windows) return [];

		$slots = [];
		$now = new DateTime('now', $tz);
		foreach ($windows as $w) {
			$slot_start = DateTime::createFromFormat('Y-m-d H:i:s', $date_ymd . ' ' . $w['time_from'], $tz);
			$slot_end   = DateTime::createFromFormat('Y-m-d H:i:s', $date_ymd . ' ' . $w['time_to'], $tz);
			if (!$slot_start || !$slot_end || $slot_end <= $slot_start) continue;
			if ($slot_start < $now) continue;

			$slots[] = [
				'starts_at' => $slot_start->format('Y-m-d H:i:s'),
				'ends_at'   => $slot_end->format('Y-m-d H:i:s'),
				'label'     => $slot_start->format('H:i') . '–' . $slot_end->format('H:i'),
			];
		}

		return $slots;
	}

	public static function debug_for_date($service_id, $date_ymd) {
		$service = BC_DB::get_service($service_id);
		$tz = self::wp_tz();

		$day = DateTime::createFromFormat('Y-m-d H:i:s', $date_ymd . ' 00:00:00', $tz);
		$today = new DateTime('now', $tz);
		$today->setTime(0, 0, 0);

		$weekday = $day ? self::weekday_1_to_7($day) : null;
		$availability = BC_DB::get_availability_windows($service_id, $date_ymd);
		$working = $weekday ? BC_DB::get_working_hours_for_weekday($service_id, $weekday) : [];

		$presets = [];
		if ($service && !empty($service['presets_json'])) {
			$decoded = json_decode((string)$service['presets_json'], true);
			if (is_array($decoded)) {
				foreach ($decoded as $p) {
					$presets[] = [
						'time_from' => (string)($p['time_from'] ?? ''),
						'time_to' => (string)($p['time_to'] ?? ''),
						'label' => (string)($p['label'] ?? ''),
					];
				}
			}
		}

		$appts = BC_DB::get_appointments_in_range($service_id, $date_ymd . ' 00:00:00', $date_ymd . ' 23:59:59');

		return [
			'timezone' => $tz->getName(),
			'service_exists' => (bool)$service,
			'service_active' => (bool)($service && (int)$service['active'] === 1),
			'service_duration_min' => $service ? (int)$service['duration_min'] : null,
			'service_step_min' => $service ? (int)$service['slot_step_min'] : null,
			'date' => $date_ymd,
			'day_valid' => (bool)$day,
			'is_past_date' => $day ? ($day < $today) : null,
			'weekday_1_7' => $weekday,
			'availability_windows_count' => is_array($availability) ? count($availability) : 0,
			'availability_windows' => is_array($availability) ? $availability : [],
			'working_hours_count' => is_array($working) ? count($working) : 0,
			'working_hours' => is_array($working) ? $working : [],
			'presets_count' => count($presets),
			'presets' => $presets,
			'appointments_count' => is_array($appts) ? count($appts) : 0,
			'appointments' => is_array($appts) ? $appts : [],
		];
	}
}
