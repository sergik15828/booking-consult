<?php
/**
 * Plugin Name: Booking Consult
 * Description: Consultation booking widget (services, calendar, time slots) with MySQL tables + REST API.
 * Version: 0.1.0
 * Author: Your Team
 */

if (!defined('ABSPATH')) exit;

define('BC_PLUGIN_VERSION', '0.1.0');
define('BC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once BC_PLUGIN_DIR . 'includes/class-bc-db.php';
require_once BC_PLUGIN_DIR . 'includes/class-bc-availability.php';
require_once BC_PLUGIN_DIR . 'includes/class-bc-rest.php';
require_once BC_PLUGIN_DIR . 'includes/class-bc-shortcode.php';
require_once BC_PLUGIN_DIR . 'includes/class-bc-admin.php';

register_activation_hook(__FILE__, ['BC_DB', 'activate']);

add_action('plugins_loaded', function () {
	BC_REST::init();
	BC_Shortcode::init();
	BC_Admin::init();
});
