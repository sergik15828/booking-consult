<?php
if (!defined('ABSPATH')) exit;

class BC_Admin {

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu']);
	}

	public static function menu() {
		add_menu_page(
			'Booking Consult', 'Booking Consult', 'manage_options',
			'bc_admin', [__CLASS__, 'page_services'],
			'dashicons-calendar-alt', 56
		);

		add_submenu_page('bc_admin', 'Услуги', 'Услуги', 'manage_options', 'bc_admin', [__CLASS__, 'page_services']);
		add_submenu_page('bc_admin', 'Записи', 'Записи', 'manage_options', 'bc_appointments', [__CLASS__, 'page_appointments']);
	}

	public static function page_services() {
		if (!current_user_can('manage_options')) return;

		global $wpdb;
		$t = BC_DB::table('services');

		if (isset($_POST['bc_save_service']) && check_admin_referer('bc_save_service')) {
			$id = (int) $_POST['id'];
			$title = sanitize_text_field($_POST['title']);
			$duration = max(5, (int) $_POST['duration_min']);
			$step = max(5, (int) $_POST['slot_step_min']);
			$active = isset($_POST['active']) ? 1 : 0;

			$wpdb->update($t, [
				'title' => $title,
				'duration_min' => $duration,
				'slot_step_min' => $step,
				'active' => $active,
			], ['id' => $id]);
			echo '<div class="updated"><p>Сохранено</p></div>';
		}

		$services = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id ASC", ARRAY_A);
		?>
		<div class="wrap">
			<h1>Услуги</h1>
			<p>Настрой длительность и шаг слотов для каждой услуги.</p>

			<?php foreach ($services as $s): ?>
				<form method="post" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin:12px 0;max-width:760px;">
					<?php wp_nonce_field('bc_save_service'); ?>
					<input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>" />
					<table class="form-table">
						<tr>
							<th>Название</th>
							<td><input name="title" value="<?php echo esc_attr($s['title']); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th>Длительность (мин)</th>
							<td><input name="duration_min" type="number" value="<?php echo (int)$s['duration_min']; ?>" /></td>
						</tr>
						<tr>
							<th>Шаг слотов (мин)</th>
							<td><input name="slot_step_min" type="number" value="<?php echo (int)$s['slot_step_min']; ?>" /></td>
						</tr>
						<tr>
							<th>Активна</th>
							<td><label><input name="active" type="checkbox" <?php checked((int)$s['active'] === 1); ?> /> Да</label></td>
						</tr>
					</table>
					<p><button class="button button-primary" name="bc_save_service" value="1">Сохранить</button></p>
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
}
