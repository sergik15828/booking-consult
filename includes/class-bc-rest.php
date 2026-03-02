<?php
if (!defined('ABSPATH')) exit;

class BC_REST {

	public static function init() {
		add_action('rest_api_init', [__CLASS__, 'routes']);
	}

	public static function routes() {
		register_rest_route('bc/v1', '/services', [
			'methods' => 'GET',
			'callback' => [__CLASS__, 'get_services'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route('bc/v1', '/calendar', [
			'methods' => 'GET',
			'callback' => [__CLASS__, 'get_calendar'],
			'permission_callback' => '__return_true',
			'args' => [
				'service_id' => ['required' => true],
			],
		]);

		register_rest_route('bc/v1', '/slots', [
			'methods' => 'GET',
			'callback' => [__CLASS__, 'get_slots'],
			'permission_callback' => '__return_true',
			'args' => [
				'service_id' => ['required' => true],
				'date' => ['required' => true],
			],
		]);

		register_rest_route('bc/v1', '/book', [
			'methods' => 'POST',
			'callback' => [__CLASS__, 'post_book'],
			'permission_callback' => [__CLASS__, 'require_logged_in'],
		]);
	}

	public static function require_logged_in() {
		return is_user_logged_in();
	}

	public static function get_services(WP_REST_Request $req) {
		return rest_ensure_response([
			'services' => BC_DB::get_services_active()
		]);
	}

	public static function get_calendar(WP_REST_Request $req) {
		$service_id = (int) $req->get_param('service_id');
		$days = BC_Availability::calendar_overview($service_id);
		return rest_ensure_response([
			'days' => $days
		]);
	}

	public static function get_slots(WP_REST_Request $req) {
		$service_id = (int) $req->get_param('service_id');
		$date = sanitize_text_field($req->get_param('date')); // Y-m-d
		$slots = BC_Availability::slots_for_date($service_id, $date);

		return rest_ensure_response([
			'date' => $date,
			'slots' => $slots
		]);
	}

	public static function post_book(WP_REST_Request $req) {
		// nonce (wp_rest)
		$nonce = $req->get_header('x_wp_nonce');
		if (!wp_verify_nonce($nonce, 'wp_rest')) {
			return new WP_Error('bad_nonce', 'Security check failed.', ['status' => 403]);
		}

		$service_id = (int) $req->get_param('service_id');
		$starts_at = sanitize_text_field($req->get_param('starts_at'));
		$ends_at = sanitize_text_field($req->get_param('ends_at'));

		$customer_name = sanitize_text_field($req->get_param('customer_name'));
		$customer_email = sanitize_email($req->get_param('customer_email'));
		$customer_phone = sanitize_text_field($req->get_param('customer_phone'));
		$notes = sanitize_textarea_field($req->get_param('notes'));

		if (!$service_id || !$starts_at || !$ends_at || !$customer_name) {
			return new WP_Error('bad_request', 'Заполните обязательные поля.', ['status' => 400]);
		}

		$id = BC_DB::create_appointment([
			'service_id' => $service_id,
			'user_id' => get_current_user_id(),
			'customer_name' => $customer_name,
			'customer_email' => $customer_email,
			'customer_phone' => $customer_phone,
			'notes' => $notes,
			'starts_at' => $starts_at,
			'ends_at' => $ends_at,
		]);

		if (is_wp_error($id)) return $id;

		return rest_ensure_response([
			'ok' => true,
			'appointment_id' => $id,
		]);
	}
}
