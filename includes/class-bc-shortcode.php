<?php
if (!defined('ABSPATH')) exit;

class BC_Shortcode {

	public static function init() {
		add_shortcode('booking_consult', [__CLASS__, 'render']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
	}

	public static function assets() {
		// Подключаем только если на странице есть шорткод
		if (!is_singular()) return;

		global $post;
		if (!$post || stripos($post->post_content, '[booking_consult') === false) return;

		wp_enqueue_style('bc-booking', BC_PLUGIN_URL . 'assets/booking.css', [], BC_PLUGIN_VERSION);
		wp_enqueue_script('bc-booking', BC_PLUGIN_URL . 'assets/booking.js', ['wp-api-fetch'], BC_PLUGIN_VERSION, true);

		wp_localize_script('bc-booking', 'BC_BOOKING', [
			'restUrl' => esc_url_raw(rest_url('bc/v1')),
			'nonce' => wp_create_nonce('wp_rest'),
			'isLoggedIn' => is_user_logged_in(),
			'loginUrl' => wp_login_url(get_permalink()),
		]);
	}

	public static function render() {
		$services = BC_DB::get_services_active();

		ob_start(); ?>
		<div class="bc-wrap">
			<div class="bc-social">
				<a class="bc-pill" href="#" aria-label="Telegram">Telegram</a>
				<a class="bc-pill" href="#" aria-label="Instagram">Instagram</a>
				<a class="bc-pill" href="#" aria-label="YouTube">YouTube</a>
				<a class="bc-pill" href="#" aria-label="Facebook">Facebook</a>
			</div>

			<div class="bc-card">
				<h2 class="bc-title">Запись на консультацию</h2>

				<?php if (!is_user_logged_in()): ?>
					<div class="bc-alert">
						Для записи нужно <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">войти</a>.
					</div>
				<?php endif; ?>

				<div class="bc-grid bc-grid-3">
					<div class="bc-field">
						<label>Выберите услугу</label>
						<select id="bc-service">
							<?php foreach ($services as $s): ?>
								<option value="<?php echo (int)$s['id']; ?>">
									<?php echo esc_html($s['title']); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="bc-field">
						<label>Год</label>
						<select id="bc-year" disabled>
							<option><?php echo esc_html(wp_date('Y')); ?></option>
						</select>
					</div>

					<div class="bc-field">
						<label>Месяц</label>
						<select id="bc-month" disabled>
							<option><?php echo esc_html(wp_date('F')); ?></option>
						</select>
					</div>
				</div>

				<div class="bc-calendar">
					<div class="bc-calendar-grid" id="bc-days">
						<!-- days injected by JS -->
					</div>
				</div>

				<div class="bc-section">
					<div class="bc-subtitle">Доступные слоты</div>
					<div class="bc-slots" id="bc-slots"></div>
				</div>

				<div class="bc-section">
					<div class="bc-subtitle">Выберите слот для записи</div>

					<div class="bc-grid bc-grid-2">
						<div class="bc-field">
							<label>Имя</label>
							<input id="bc-name" type="text" placeholder="" />
						</div>
						<div class="bc-field">
							<label>Email</label>
							<input id="bc-email" type="email" placeholder="" />
						</div>
						<div class="bc-field">
							<label>Телефон</label>
							<input id="bc-phone" type="text" placeholder="" />
						</div>
						<div class="bc-field">
							<label>Кратко запрос</label>
							<input id="bc-notes" type="text" placeholder="" />
						</div>
					</div>

					<button class="bc-btn-primary" id="bc-book" type="button" disabled>Забронировать</button>
					<div class="bc-hint" id="bc-hint"></div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
