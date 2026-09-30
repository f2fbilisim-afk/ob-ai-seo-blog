<?php
/**
 * Plugin Name: OB AI SEO Blog
 * Plugin URI:  https://github.com/f2fbilisim-afk/ob-ai-seo-blog
 * Description: Anahtar kelime listesinden OpenAI ile toplu SEO blog üretir, Rank Math alanlarını doldurur, kapak görseli ekler; anında veya planlı yayınlar.
 * Version:     1.0.3
 * Author:      F2F Bilişim
 * Author URI:  https://www.f2fbilisim.com
 * Text Domain: ob-ai-seo-blog
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI:   https://raw.githubusercontent.com/f2fbilisim-afk/ob-ai-seo-blog/main/updates/ob-ai-seo-blog.json
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OB_AI_SEO_BLOG_VERSION', '1.0.3' );
define( 'OB_AI_SEO_BLOG_FILE', __FILE__ );
define( 'OB_AI_SEO_BLOG_DIR', plugin_dir_path( __FILE__ ) );
define( 'OB_AI_SEO_BLOG_URL', plugin_dir_url( __FILE__ ) );

require_once OB_AI_SEO_BLOG_DIR . 'includes/class-ob-ai-settings.php';
require_once OB_AI_SEO_BLOG_DIR . 'includes/class-f2f-saas-client.php';
require_once OB_AI_SEO_BLOG_DIR . 'includes/class-ob-ai-saas-client.php';
require_once OB_AI_SEO_BLOG_DIR . 'includes/class-ob-ai-openai.php';
require_once OB_AI_SEO_BLOG_DIR . 'includes/class-ob-ai-rank-math.php';
require_once OB_AI_SEO_BLOG_DIR . 'includes/class-ob-ai-post-creator.php';
require_once OB_AI_SEO_BLOG_DIR . 'includes/class-ob-ai-queue.php';
require_once OB_AI_SEO_BLOG_DIR . 'includes/class-ob-ai-admin.php';
require_once OB_AI_SEO_BLOG_DIR . 'includes/class-ob-ai-updater.php';

final class OB_AI_SEO_Blog_Plugin {

	public static function init(): void {
		OB_AI_Settings::init();
		OB_AI_Queue::init();
		OB_AI_Admin::init();
		if ( is_admin() ) {
			OB_AI_Updater::init();
		}
	}

	public static function activate(): void {
		OB_AI_Queue::create_table();
		if ( ! wp_next_scheduled( 'ob_ai_seo_blog_process_queue' ) ) {
			wp_schedule_event( time(), 'every_minute', 'ob_ai_seo_blog_process_queue' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'ob_ai_seo_blog_process_queue' );
	}
}

add_action( 'plugins_loaded', array( 'OB_AI_SEO_Blog_Plugin', 'init' ) );
register_activation_hook( __FILE__, array( 'OB_AI_SEO_Blog_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OB_AI_SEO_Blog_Plugin', 'deactivate' ) );

add_filter(
	'cron_schedules',
	static function ( $schedules ) {
		if ( ! isset( $schedules['every_minute'] ) ) {
			$schedules['every_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Her dakika', 'ob-ai-seo-blog' ),
			);
		}
		return $schedules;
	}
);
