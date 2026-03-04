<?php
if (!defined('ABSPATH')) exit;

class BC_Availability {

	private static function wp_tz() {
		$tz = wp_timezone();
		return $tz ?: new DateTimeZone('UTC');
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

		$windows = BC_DB::get_availability_windows($service_id, $date_ymd);
		if (!$windows) return [];

		$slots = [];

		foreach ($windows as $w) {
			$from = DateTime::createFromFormat('Y-m-d H:i:s', $date_ymd . ' ' . $w['time_from'], $tz);
			$to   = DateTime::createFromFormat('Y-m-d H:i:s', $date_ymd . ' ' . $w['time_to'], $tz);
			if (!$from || !$to) continue;

			// На фронте показываем именно окна доступности, заданные в админке.
			$slot_start = clone $from;
			$slot_end = clone $to;
			if ($slot_end <= $slot_start) continue;

			// если сегодня — слоты раньше "сейчас" убираем
			$now = new DateTime('now', $tz);
			if ($slot_start >= $now) {
				$slots[] = [
					'starts_at' => $slot_start->format('Y-m-d H:i:s'),
					'ends_at'   => $slot_end->format('Y-m-d H:i:s'),
					'label'     => $slot_start->format('H:i') . '–' . $slot_end->format('H:i'),
				];
			}
		}

		if (!$slots) return [];

		// Убираем занятые
		$range_from = $date_ymd . ' 00:00:00';
		$range_to   = $date_ymd . ' 23:59:59';
		$appts = BC_DB::get_appointments_in_range($service_id, $range_from, $range_to);

		if (!$appts) return $slots;

		$free = [];
		foreach ($slots as $s) {
			$taken = false;
			foreach ($appts as $a) {
				// пересечение
				if ($a['starts_at'] < $s['ends_at'] && $a['ends_at'] > $s['starts_at']) {
					$taken = true;
					break;
				}
			}
			if (!$taken) $free[] = $s;
		}

		return $free;
	}
}
