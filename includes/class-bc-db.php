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
		$t_appts    = self::table('appointments');
		$t_av = self::table('availability');

		$sql_services = "CREATE TABLE {$t_services} (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          title VARCHAR(190) NOT NULL,
          duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
          slot_step_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
          presets_json LONGTEXT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY active (active)
        ) {$charset_collate};";

		$sql_appts = "CREATE TABLE {$t_appts} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      service_id BIGINT UNSIGNED NOT NULL,
      service_title VARCHAR(190) NULL,
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

    $sql_av = "CREATE TABLE {$t_av} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      service_id BIGINT UNSIGNED NOT NULL,
      date DATE NOT NULL,
      time_from TIME NOT NULL,
      time_to TIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY service_date (service_id, date)
    ) {$charset_collate};";

		dbDelta($sql_services);
		dbDelta($sql_appts);
		dbDelta($sql_av);

		self::seed_defaults();
	}

	private static function seed_defaults() {
		global $wpdb;

		$t_services = self::table('services');

		$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t_services}");
		if ($count > 0) return;

		// 3 услуги (примерные длительности/шаги — поменяешь в админке)
		$services = [
			['Индивидуальная сессия', 60, 30],
			['Разбор запроса',        45, 15],
			['Сопровождение',         90, 30],
		];

		$default_presets = json_encode([
          ['time_from'=>'11:00','time_to'=>'12:30','label'=>'11:00–12:30'],
          ['time_from'=>'13:00','time_to'=>'14:30','label'=>'13:00–14:30'],
          ['time_from'=>'17:00','time_to'=>'18:30','label'=>'17:00–18:30'],
        ], JSON_UNESCAPED_UNICODE);

		foreach ($services as $s) {
			$wpdb->insert($t_services, [
				'title' => $s[0],
				'duration_min' => $s[1],
				'slot_step_min' => $s[2],
				'presets_json' => $default_presets,
				'active' => 1
			]);
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
    $service = self::get_service((int) $data['service_id']);
    $service_title = $service['title'] ?? '';

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
				'service_title' => $service_title,
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

    public static function get_availability_windows($service_id, $date_ymd) {
      global $wpdb;
      $t = self::table('availability');
      return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t} WHERE service_id=%d AND date=%s ORDER BY time_from ASC",
        $service_id, $date_ymd
      ), ARRAY_A);
    }

    public static function replace_availability_for_date($service_id, $date_ymd, $windows) {
      global $wpdb;
      $t = self::table('availability');

      $wpdb->query('START TRANSACTION');

      $del = $wpdb->delete($t, ['service_id' => $service_id, 'date' => $date_ymd]);
      if ($del === false) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('db_delete_failed', $wpdb->last_error ?: 'Delete failed');
      }

      foreach ($windows as $w) {
        $ok = $wpdb->insert($t, [
          'service_id' => $service_id,
          'date' => $date_ymd,
          'time_from' => $w['time_from'],
          'time_to' => $w['time_to'],
        ]);

        if ($ok === false) {
          $wpdb->query('ROLLBACK');
          return new WP_Error('db_insert_failed', $wpdb->last_error ?: 'Insert failed');
        }
      }

      $wpdb->query('COMMIT');
      return true;
    }

    public static function maybe_upgrade() {
      global $wpdb;
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';

      // 1) убедимся, что таблица availability существует (как мы делали раньше)
      $t_av = self::table('availability');
      $exists_av = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_av));
      if ($exists_av !== $t_av) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql_av = "CREATE TABLE {$t_av} (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          service_id BIGINT UNSIGNED NOT NULL,
          date DATE NOT NULL,
          time_from TIME NOT NULL,
          time_to TIME NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY service_date (service_id, date)
        ) {$charset_collate};";
        dbDelta($sql_av);
      }

      $t_appts = self::table('appointments');

      // 2) добавим колонку presets_json в services, если её нет
      $t_services = self::table('services');
      $col = $wpdb->get_var($wpdb->prepare(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = %s
           AND COLUMN_NAME = 'presets_json'",
        $t_services
      ));

      if (!$col) {
        $wpdb->query("ALTER TABLE {$t_services} ADD COLUMN presets_json LONGTEXT NULL");
      }

      // 3) добавим service_title в appointments, если его нет
      $appts_col = $wpdb->get_var($wpdb->prepare(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = %s
           AND COLUMN_NAME = 'service_title'",
        $t_appts
      ));

      if (!$appts_col) {
        $wpdb->query("ALTER TABLE {$t_appts} ADD COLUMN service_title VARCHAR(190) NULL AFTER service_id");
      }

      // 4) заполним service_title для существующих записей из текущих услуг
      $wpdb->query("
        UPDATE {$t_appts} a
        INNER JOIN {$t_services} s ON s.id = a.service_id
        SET a.service_title = s.title
        WHERE a.service_title IS NULL OR a.service_title = ''
      ");

      // 5) удаляем legacy 4-ю дефолтную услугу и её доступность
      $legacy_service_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$t_services} WHERE title = %s",
        'Экспресс-консультация'
      ));

      if ($legacy_service_ids) {
        foreach ($legacy_service_ids as $legacy_service_id) {
          $legacy_service_id = (int) $legacy_service_id;

          $wpdb->update(
            $t_appts,
            ['service_title' => 'Экспресс-консультация'],
            ['service_id' => $legacy_service_id],
            ['%s'],
            ['%d']
          );

          $wpdb->delete($t_av, ['service_id' => $legacy_service_id], ['%d']);
          $wpdb->delete($t_services, ['id' => $legacy_service_id], ['%d']);
        }
      }
    }
}
