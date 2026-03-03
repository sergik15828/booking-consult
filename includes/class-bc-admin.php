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
		add_submenu_page('bc_admin', 'Расписание', 'Расписание', 'manage_options', 'bc_schedule', [__CLASS__, 'page_schedule']);
		add_submenu_page('bc_admin', 'Доступность', 'Доступность', 'manage_options', 'bc_availability', [__CLASS__, 'page_availability']);
	}

	public static function page_services() {
      if (!current_user_can('manage_options')) return;

      global $wpdb;
      $t = BC_DB::table('services');

      // SAVE
      if (isset($_POST['bc_save_service']) && check_admin_referer('bc_save_service')) {
        $id       = (int) ($_POST['id'] ?? 0);
        $title    = sanitize_text_field($_POST['title'] ?? '');
        $duration = max(5, (int) ($_POST['duration_min'] ?? 60));
        $step     = max(5, (int) ($_POST['slot_step_min'] ?? 30));
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
        <p>Настрой длительность, шаг слотов и пресеты интервалов для каждой услуги.</p>

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
                <th>Длительность (мин)</th>
                <td>
                  <input name="duration_min" type="number" min="5" step="1" value="<?php echo (int) $s['duration_min']; ?>" />
                </td>
              </tr>

              <tr>
                <th>Шаг слотов (мин)</th>
                <td>
                  <input name="slot_step_min" type="number" min="5" step="1" value="<?php echo (int) $s['slot_step_min']; ?>" />
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
                <th>Пресеты (по строке)</th>
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

    public static function page_schedule() {
      if (!current_user_can('manage_options')) return;

      global $wpdb;
      $t_services = BC_DB::table('services');
      $t_hours    = BC_DB::table('working_hours');

      $services = $wpdb->get_results("SELECT * FROM {$t_services} ORDER BY id ASC", ARRAY_A);
      $service_id = isset($_GET['service_id']) ? (int) $_GET['service_id'] : (int)($services[0]['id'] ?? 0);

      $weekdays = [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
        6 => 'Суббота',
        7 => 'Воскресенье',
      ];

      // Сохранение
      if (isset($_POST['bc_save_schedule']) && check_admin_referer('bc_save_schedule')) {
        $service_id_post = (int) $_POST['service_id'];

        // чистим старые окна для услуги
        $wpdb->delete($t_hours, ['service_id' => $service_id_post]);

        // ожидаем формат: windows[weekday][] = "HH:MM-HH:MM"
        $windows = $_POST['windows'] ?? [];
        foreach ($windows as $wd => $list) {
          $wd = (int) $wd;
          if ($wd < 1 || $wd > 7) continue;

          if (!is_array($list)) continue;

          foreach ($list as $row) {
            $row = sanitize_text_field($row);
            if (!$row) continue;

            // "11:00-14:30"
            if (!preg_match('/^([0-2]\d):([0-5]\d)\-([0-2]\d):([0-5]\d)$/', $row, $m)) continue;

            $from = $m[1] . ':' . $m[2] . ':00';
            $to   = $m[3] . ':' . $m[4] . ':00';

            // простая проверка что from < to
            if (strtotime($from) >= strtotime($to)) continue;

            $wpdb->insert($t_hours, [
              'service_id' => $service_id_post,
              'weekday' => $wd,
              'time_from' => $from,
              'time_to' => $to,
            ]);
          }
        }

        echo '<div class="updated"><p>Расписание сохранено</p></div>';
        $service_id = $service_id_post;
      }

      // текущие окна
      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t_hours} WHERE service_id=%d ORDER BY weekday ASC, time_from ASC",
        $service_id
      ), ARRAY_A);

      $by_day = [];
      foreach ($rows as $r) {
        $wd = (int)$r['weekday'];
        $by_day[$wd][] = substr($r['time_from'],0,5) . '-' . substr($r['time_to'],0,5);
      }

      ?>
      <div class="wrap">
        <h1>Расписание</h1>

        <form method="get" style="margin: 10px 0 16px;">
          <input type="hidden" name="page" value="bc_schedule" />
          <label><strong>Услуга:</strong></label>
          <select name="service_id" onchange="this.form.submit()">
            <?php foreach ($services as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php selected((int)$s['id'] === $service_id); ?>>
                <?php echo esc_html($s['title']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <form method="post" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;max-width:900px;">
          <?php wp_nonce_field('bc_save_schedule'); ?>
          <input type="hidden" name="service_id" value="<?php echo (int)$service_id; ?>" />

          <p style="margin-top:0;color:#475569;">
            Формат окна: <code>11:00-14:30</code>. Можно несколько окон на день (например утро + вечер).
          </p>

          <table class="widefat striped">
            <thead>
              <tr>
                <th style="width:180px;">День</th>
                <th>Окна времени</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($weekdays as $wd => $label): ?>
              <?php $list = $by_day[$wd] ?? ['']; ?>
              <tr>
                <td><strong><?php echo esc_html($label); ?></strong></td>
                <td>
                  <div class="bc-admin-windows" data-weekday="<?php echo (int)$wd; ?>">
                    <?php foreach ($list as $val): ?>
                      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                        <input type="text" name="windows[<?php echo (int)$wd; ?>][]" value="<?php echo esc_attr($val); ?>" placeholder="11:00-14:30" style="width:160px;">
                        <button type="button" class="button bc-remove-window">Удалить</button>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" class="button bc-add-window" data-weekday="<?php echo (int)$wd; ?>">+ Добавить окно</button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <p style="margin-top:14px;">
            <button class="button button-primary" name="bc_save_schedule" value="1">Сохранить расписание</button>
          </p>
        </form>
      </div>

      <script>
        (function(){
          function addRow(container){
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;';
            const wd = container.getAttribute('data-weekday');

            row.innerHTML =
              '<input type="text" name="windows['+wd+'][]" value="" placeholder="11:00-14:30" style="width:160px;">' +
              '<button type="button" class="button bc-remove-window">Удалить</button>';

            container.appendChild(row);
          }

          document.addEventListener('click', function(e){
            if (e.target.classList.contains('bc-add-window')) {
              const wd = e.target.getAttribute('data-weekday');
              const container = document.querySelector('.bc-admin-windows[data-weekday="'+wd+'"]');
              if (container) addRow(container);
            }

            if (e.target.classList.contains('bc-remove-window')) {
              const row = e.target.closest('div');
              const container = e.target.closest('.bc-admin-windows');
              if (row && container) {
                // не даём удалить последнюю строку — оставим пустую
                if (container.querySelectorAll('input').length <= 1) {
                  container.querySelector('input').value = '';
                } else {
                  row.remove();
                }
              }
            }
          });
        })();
      </script>
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
