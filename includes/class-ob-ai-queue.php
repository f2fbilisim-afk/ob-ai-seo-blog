<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OB_AI_Queue {

	const TABLE = 'ob_ai_seo_blog_queue';

	public static function init(): void {
		add_action( 'ob_ai_seo_blog_process_queue', array( __CLASS__, 'process_pending' ) );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function create_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id varchar(32) NOT NULL,
			focus_keyword varchar(255) NOT NULL,
			publish_at_gmt datetime NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			post_id bigint(20) unsigned NULL,
			error_message text NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY batch_id (batch_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * @param array<int, array{keyword:string, publish_local:?string}> $items
	 */
	public static function enqueue_batch( array $items, ?string $schedule_start_local, int $interval_minutes ): string {
		global $wpdb;

		$batch_id = wp_generate_password( 12, false, false );
		$now_gmt  = current_time( 'mysql', true );

		$start_gmt = null;
		if ( $schedule_start_local ) {
			$start_gmt = get_gmt_from_date( $schedule_start_local );
		}

		$index = 0;
		foreach ( $items as $item ) {
			$keyword = trim( $item['keyword'] ?? '' );
			if ( '' === $keyword ) {
				continue;
			}

			$publish_gmt = $now_gmt;
			$line_local  = isset( $item['publish_local'] ) ? trim( (string) $item['publish_local'] ) : '';
			if ( $line_local ) {
				$publish_gmt = get_gmt_from_date( $line_local );
			} elseif ( $start_gmt ) {
				$publish_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $start_gmt ) + ( $index * $interval_minutes * 60 ) );
			}
			++$index;

			$wpdb->insert(
				self::table_name(),
				array(
					'batch_id'        => $batch_id,
					'focus_keyword'   => $keyword,
					'publish_at_gmt'  => $publish_gmt,
					'status'          => 'pending',
					'created_at_gmt'  => $now_gmt,
					'updated_at_gmt'  => $now_gmt,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		wp_schedule_single_event( time() + 5, 'ob_ai_seo_blog_process_queue' );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return $batch_id;
	}

	public static function process_pending(): void {
		global $wpdb;
		$table = self::table_name();

		// Tek seferde bir iş — OpenAI uzun sürebilir.
		$row = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT 1"
		);

		if ( ! $row ) {
			return;
		}

		$wpdb->update(
			$table,
			array(
				'status'         => 'processing',
				'updated_at_gmt' => current_time( 'mysql', true ),
			),
			array( 'id' => $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$result = OB_AI_Post_Creator::create_from_queue_item( $row );

		if ( is_wp_error( $result ) ) {
			$wpdb->update(
				$table,
				array(
					'status'         => 'failed',
					'error_message'  => $result->get_error_message(),
					'updated_at_gmt' => current_time( 'mysql', true ),
				),
				array( 'id' => $row->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			self::schedule_next_if_pending();
			return;
		}

		$wpdb->update(
			$table,
			array(
				'status'         => 'done',
				'post_id'        => (int) $result,
				'updated_at_gmt' => current_time( 'mysql', true ),
			),
			array( 'id' => $row->id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		self::schedule_next_if_pending();
	}

	private static function schedule_next_if_pending(): void {
		global $wpdb;
		$table   = self::table_name();
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
		if ( $pending > 0 ) {
			wp_schedule_single_event( time() + 15, 'ob_ai_seo_blog_process_queue' );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	public static function get_batch_rows( string $batch_id ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %s ORDER BY id ASC", $batch_id ) );
	}

	public static function recent_batches( int $limit = 10 ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT batch_id, MIN(created_at_gmt) AS created_at_gmt,
				SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) AS done_count,
				SUM(CASE WHEN status='pending' OR status='processing' THEN 1 ELSE 0 END) AS pending_count,
				SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed_count,
				COUNT(*) AS total
				FROM {$table} GROUP BY batch_id ORDER BY created_at_gmt DESC LIMIT %d",
				$limit
			)
		);
	}
}
