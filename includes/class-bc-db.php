<?php
if (!defined('ABSPATH')) exit;

class BC_DB {
	public static function table($name) {
		global $wpdb;
		return $wpdb->prefix . 'bc_' . $name;
	}

	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$t_services = self::table('services');
		$t_hours    = self::table('working_hours');
		$t_appts    = self::table('appointments');

		$sql_services = "CREATE TABLE {$t_services} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      title VARCHAR(190) NOT NULL,
      duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
      slot_step_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY active (active)
    ) {$charset_collate};";

		$sql_hours = "CREATE TABLE {$t_hours} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      service_id BIGINT UNSIGNED NOT NULL,
      weekday TINYINT UNSIGNED NOT NULL, /* 1=Mon ... 7=Sun */
      time_from TIME NOT NULL,
      time_to TIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY service_weekday (service_id, weekday)
    ) {$charset_collate};";

		$sql_appts = "CREATE TABLE {$t_appts} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      service_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NULL,
      customer_name VARCHAR(190) NOT NULL,
      customer_email VARCHAR(190) NULL,
      customer_phone VARCHAR(50) NULL,
      notes TEXT NULL,
      starts_at DATETIME NOT NULL,
      ends_at DATETIME NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'booked',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY service_starts (service_id, starts_at),
      KEY starts_at (starts_at),
      KEY status (status)
    ) {$charset_collate};";

		dbDelta($sql_services);
		dbDelta($sql_hours);
		dbDelta($sql_appts);

		self::seed_defaults();
	}

	private static function seed_defaults() {
		global $wpdb;

		$t_services = self::table('services');
		$t_hours    = self::table('working_hours');

		$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t_services}");
		if ($count > 0) return;

		// 4 услуги (примерные длительности/шаги — поменяешь в админке)
		$services = [
			['Индивидуальная сессия', 60, 30],
			['Разбор запроса',        45, 15],
			['Сопровождение',         90, 30],
			['Экспресс-консультация', 30, 30],
		];

		foreach ($services as $s) {
			$wpdb->insert($t_services, [
				'title' => $s[0],
				'duration_min' => $s[1],
				'slot_step_min' => $s[2],
				'active' => 1
			]);
			$service_id = (int) $wpdb->insert_id;

			// Дефолт: Пн-Пт 11:00-18:30, Сб 11:00-14:30, Вс выходной (как на твоём UI похоже)
			$hours = [
				[1, '11:00:00', '18:30:00'],
				[2, '11:00:00', '18:30:00'],
				[3, '11:00:00', '18:30:00'],
				[4, '11:00:00', '18:30:00'],
				[5, '11:00:00', '18:30:00'],
				[6, '11:00:00', '14:30:00'],
			];
			foreach ($hours as $h) {
				$wpdb->insert($t_hours, [
					'service_id' => $service_id,
					'weekday' => $h[0],
					'time_from' => $h[1],
					'time_to' => $h[2],
				]);
			}
		}
	}

	public static function get_services_active() {
		global $wpdb;
		$t = self::table('services');
		return $wpdb->get_results("SELECT * FROM {$t} WHERE active=1 ORDER BY id ASC", ARRAY_A);
	}

	public static function get_service($service_id) {
		global $wpdb;
		$t = self::table('services');
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $service_id), ARRAY_A);
	}

	public static function get_working_hours_for_weekday($service_id, $weekday) {
		global $wpdb;
		$t = self::table('working_hours');
		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$t} WHERE service_id=%d AND weekday=%d ORDER BY time_from ASC",
			$service_id, $weekday
		), ARRAY_A);
	}

	public static function get_appointments_in_range($service_id, $from_dt, $to_dt) {
		global $wpdb;
		$t = self::table('appointments');
		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$t}
       WHERE service_id=%d AND status='booked'
         AND starts_at < %s AND ends_at > %s
       ORDER BY starts_at ASC",
			$service_id, $to_dt, $from_dt
		), ARRAY_A);
	}

	/**
	 * Создание брони с проверкой пересечений (транзакция).
	 */
	public static function create_appointment($data) {
		global $wpdb;
		$t = self::table('appointments');

		$wpdb->query('START TRANSACTION');

		try {
			// проверка пересечений
			$overlap = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$t}
         WHERE service_id=%d AND status='booked'
           AND starts_at < %s AND ends_at > %s",
				$data['service_id'],
				$data['ends_at'],
				$data['starts_at']
			));

			if ($overlap > 0) {
				$wpdb->query('ROLLBACK');
				return new WP_Error('slot_taken', 'Этот слот уже занят. Обновите страницу и выберите другой.');
			}

			$ok = $wpdb->insert($t, [
				'service_id' => $data['service_id'],
				'user_id' => $data['user_id'],
				'customer_name' => $data['customer_name'],
				'customer_email' => $data['customer_email'],
				'customer_phone' => $data['customer_phone'],
				'notes' => $data['notes'],
				'starts_at' => $data['starts_at'],
				'ends_at' => $data['ends_at'],
				'status' => 'booked',
			]);

			if (!$ok) {
				$wpdb->query('ROLLBACK');
				return new WP_Error('db_insert_failed', 'Не удалось сохранить запись. Попробуйте ещё раз.');
			}

			$id = (int) $wpdb->insert_id;
			$wpdb->query('COMMIT');
			return $id;

		} catch (Throwable $e) {
			$wpdb->query('ROLLBACK');
			return new WP_Error('exception', 'Ошибка сервера: ' . $e->getMessage());
		}
	}
}
