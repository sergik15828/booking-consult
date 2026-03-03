<?php
if (!defined('ABSPATH')) exit;

class BC_Admin {

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
	}

	public static function menu() {
		add_menu_page(
			'Booking Consult', 'Booking Consult', 'manage_options',
			'bc_admin', [__CLASS__, 'page_services'],
			'dashicons-calendar-alt', 56
		);

		add_submenu_page('bc_admin', 'Услуги', 'Услуги', 'manage_options', 'bc_admin', [__CLASS__, 'page_services']);
		add_submenu_page('bc_admin', 'Записи', 'Записи', 'manage_options', 'bc_appointments', [__CLASS__, 'page_appointments']);
		add_submenu_page('bc_admin', 'Доступность', 'Доступность', 'manage_options', 'bc_availability', [__CLASS__, 'page_availability']);
	}

	public static function page_services() {
      if (!current_user_can('manage_options')) return;

      global $wpdb;
      $t = BC_DB::table('services');

      // SAVE
      if (isset($_POST['bc_save_service']) && check_admin_referer('bc_save_service')) {
        $id       = (int) ($_POST['id'] ?? 0);
        $service  = $id ? BC_DB::get_service($id) : null;
        $title    = sanitize_text_field($_POST['title'] ?? '');
        $duration = max(5, (int) ($service['duration_min'] ?? 60));
        $step     = max(5, (int) ($service['slot_step_min'] ?? 30));
        $active   = isset($_POST['active']) ? 1 : 0;

        // Presets: each line "HH:MM-HH:MM"
        $presets_lines = (string) ($_POST['presets_lines'] ?? '');
        $presets = [];

        foreach (preg_split("/\r\n|\n|\r/", $presets_lines) as $line) {
          $line = trim($line);
          if ($line === '') continue;

          if (!preg_match('/^([0-2]\d):([0-5]\d)\-([0-2]\d):([0-5]\d)$/', $line, $m)) {
            continue;
          }

          $from = $m[1] . ':' . $m[2];
          $to   = $m[3] . ':' . $m[4];

          // ensure from < to
          if (strtotime($from . ':00') >= strtotime($to . ':00')) continue;

          $presets[] = [
            'time_from' => $from,
            'time_to'   => $to,
            'label'     => $from . '–' . $to,
          ];
        }

        $updated = $wpdb->update(
          $t,
          [
            'title'        => $title,
            'duration_min' => $duration,
            'slot_step_min'=> $step,
            'presets_json' => wp_json_encode($presets, JSON_UNESCAPED_UNICODE),
            'active'       => $active,
          ],
          ['id' => $id],
          ['%s','%d','%d','%s','%d'],
          ['%d']
        );

        if ($updated === false) {
          echo '<div class="notice notice-error"><p>Ошибка сохранения: ' . esc_html($wpdb->last_error ?: 'unknown') . '</p></div>';
        } else {
          echo '<div class="notice notice-success"><p>Сохранено</p></div>';
        }
      }

      $services = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id ASC", ARRAY_A);
      ?>
      <div class="wrap">
        <h1>Услуги</h1>
        <p>Настрой название, активность и пресеты интервалов для каждой услуги.</p>

        <?php foreach ($services as $s): ?>
          <?php
            // presets textarea lines (from presets_json)
            $lines = [];
            $arr = json_decode($s['presets_json'] ?? '[]', true);
            if (is_array($arr)) {
              foreach ($arr as $p) {
                if (!empty($p['time_from']) && !empty($p['time_to'])) {
                  $lines[] = $p['time_from'] . '-' . $p['time_to'];
                }
              }
            }
            $presets_text = implode("\n", $lines);
          ?>

          <form method="post" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin:12px 0;max-width:760px;">
            <?php wp_nonce_field('bc_save_service'); ?>
            <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>" />

            <table class="form-table">
              <tr>
                <th>Название</th>
                <td>
                  <input name="title" value="<?php echo esc_attr($s['title']); ?>" class="regular-text" />
                </td>
              </tr>

              <tr>
                <th>Активна</th>
                <td>
                  <label>
                    <input name="active" type="checkbox" <?php checked((int)$s['active'] === 1); ?> />
                    Да
                  </label>
                </td>
              </tr>

              <tr>
                <th>Время (по строке)</th>
                <td>
                  <textarea name="presets_lines" rows="6" class="large-text" placeholder="11:00-12:30"><?php echo esc_textarea($presets_text); ?></textarea>
                  <p class="description">Формат: <code>HH:MM-HH:MM</code>, каждый пресет с новой строки.</p>
                </td>
              </tr>
            </table>

            <p>
              <button class="button button-primary" name="bc_save_service" value="1">Сохранить</button>
            </p>
          </form>
        <?php endforeach; ?>
      </div>
      <?php
    }

	public static function page_appointments() {
		if (!current_user_can('manage_options')) return;

		global $wpdb;
		$t = BC_DB::table('appointments');
		$ts = BC_DB::table('services');

		$rows = $wpdb->get_results("
      SELECT a.*, s.title AS service_title
      FROM {$t} a
      LEFT JOIN {$ts} s ON s.id=a.service_id
      ORDER BY a.created_at DESC
      LIMIT 200
    ", ARRAY_A);

		?>
		<div class="wrap">
			<h1>Записи</h1>
			<table class="widefat striped">
				<thead>
				<tr>
					<th>ID</th>
					<th>Услуга</th>
					<th>Дата/время</th>
					<th>Клиент</th>
					<th>Контакты</th>
					<th>Статус</th>
					<th>Создано</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($rows as $r): ?>
					<tr>
						<td><?php echo (int)$r['id']; ?></td>
						<td><?php echo esc_html($r['service_title'] ?: ('#'.$r['service_id'])); ?></td>
						<td><?php echo esc_html($r['starts_at'] . ' — ' . $r['ends_at']); ?></td>
						<td><?php echo esc_html($r['customer_name']); ?></td>
						<td>
							<?php echo esc_html($r['customer_email']); ?><br/>
							<?php echo esc_html($r['customer_phone']); ?>
						</td>
						<td><?php echo esc_html($r['status']); ?></td>
						<td><?php echo esc_html($r['created_at']); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

    public static function assets($hook) {
      if (empty($_GET['page']) || $_GET['page'] !== 'bc_availability') return;

      $services = BC_DB::get_services_active();
      $service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : (int)($services[0]['id'] ?? 0);
      $service = $service_id ? BC_DB::get_service($service_id) : null;

      $presets = [];
      if ($service && !empty($service['presets_json'])) {
        $decoded = json_decode($service['presets_json'], true);
        if (is_array($decoded)) $presets = $decoded;
      }

      // fallback если вдруг пусто
      if (!$presets) {
        $presets = [
          ['time_from'=>'11:00','time_to'=>'12:30','label'=>'11:00–12:30'],
          ['time_from'=>'13:00','time_to'=>'14:30','label'=>'13:00–14:30'],
          ['time_from'=>'17:00','time_to'=>'18:30','label'=>'17:00–18:30'],
        ];
      }

      wp_enqueue_style('bc-admin-availability', BC_PLUGIN_URL . 'assets/admin-availability.css', [], BC_PLUGIN_VERSION);
      wp_enqueue_script('bc-admin-availability', BC_PLUGIN_URL . 'assets/admin-availability.js', ['wp-api-fetch'], BC_PLUGIN_VERSION, true);

      wp_localize_script('bc-admin-availability', 'BC_ADMIN_AV', [
        'restUrl' => esc_url_raw(rest_url('bc/v1')),
        'nonce' => wp_create_nonce('wp_rest'),
        'presets' => $presets,
      ]);
    }

    public static function page_availability() {
      if (!current_user_can('manage_options')) return;

      $services = BC_DB::get_services_active();
      $service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : (int)($services[0]['id'] ?? 0);
      ?>
      <div class="wrap">
        <h1>Доступность (свободные даты)</h1>

        <form method="get" style="margin: 10px 0 16px;">
          <input type="hidden" name="page" value="bc_availability" />
          <label><strong>Услуга:</strong></label>
          <select name="service_id" onchange="this.form.submit()">
            <?php foreach ($services as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php selected((int)$s['id'] === $service_id); ?>>
                <?php echo esc_html($s['title']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <div class="bcav-layout" data-service-id="<?php echo (int)$service_id; ?>">
          <div class="bcav-card">
            <div class="bcav-title">Выберите дату</div>
            <div class="bcav-grid" id="bcav-days"></div>
            <div class="bcav-note">Показаны даты: сегодня → 30 дней вперёд</div>
          </div>

          <div class="bcav-card">
            <div class="bcav-title">
              Интервалы на дату: <span id="bcav-picked-date">—</span>
            </div>

            <div class="bcav-subtitle">Пресеты</div>
            <div class="bcav-presets" id="bcav-presets"></div>

            <div class="bcav-subtitle" style="margin-top:14px;">Выбранные интервалы</div>
            <div class="bcav-windows" id="bcav-windows"></div>

            <div class="bcav-actions">
              <button class="button button-secondary" type="button" id="bcav-clear">Очистить дату</button>
              <button class="button button-primary" type="button" id="bcav-save">Сохранить</button>
            </div>

            <div class="bcav-hint" id="bcav-hint"></div>
          </div>
        </div>
      </div>
      <?php
    }
}
