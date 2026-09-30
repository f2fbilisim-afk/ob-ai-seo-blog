<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OB_AI_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ob_ai_seo_blog_generate', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_notices', array( __CLASS__, 'warn_legacy_duplicate_plugin' ) );
	}

	public static function warn_legacy_duplicate_plugin(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$duplicates = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( false !== stripos( (string) ( $data['Name'] ?? '' ), 'SEO Blog' )
				&& plugin_basename( OB_AI_SEO_BLOG_FILE ) !== $file ) {
				$duplicates[] = $data['Name'] . ' (' . ( $data['Version'] ?? '?' ) . ') → ' . $file;
			}
		}
		if ( empty( $duplicates ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>F2F AI SEO Blog:</strong> ';
		echo esc_html__( 'Eski eklenti kopyası hâlâ yüklü. Etkisizleştirip silin; yalnızca F2F AI SEO Blog 1.1.0 kalsın:', 'ob-ai-seo-blog' );
		echo '<br /><code>' . esc_html( implode( ' | ', $duplicates ) ) . '</code></p></div>';
	}

	public static function menu(): void {
		add_menu_page(
			__( 'AI SEO İçerik Üretimi', 'ob-ai-seo-blog' ),
			__( 'AI SEO', 'ob-ai-seo-blog' ),
			'publish_posts',
			'ob-ai-seo-blog',
			array( __CLASS__, 'render_generator' ),
			'dashicons-star-filled',
			58
		);

		add_submenu_page(
			'ob-ai-seo-blog',
			__( 'İçerik Üretimi', 'ob-ai-seo-blog' ),
			__( 'İçerik Üretimi', 'ob-ai-seo-blog' ),
			'publish_posts',
			'ob-ai-seo-blog',
			array( __CLASS__, 'render_generator' )
		);

		add_submenu_page(
			'ob-ai-seo-blog',
			__( 'Kuyruk', 'ob-ai-seo-blog' ),
			__( 'Kuyruk', 'ob-ai-seo-blog' ),
			'publish_posts',
			'ob-ai-seo-blog-queue',
			array( __CLASS__, 'render_queue' )
		);

		add_submenu_page(
			'ob-ai-seo-blog',
			__( 'Ayarlar', 'ob-ai-seo-blog' ),
			__( 'Ayarlar', 'ob-ai-seo-blog' ),
			'manage_options',
			'ob-ai-seo-blog-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'ob-ai-seo-blog' ) ) {
			return;
		}
		wp_enqueue_style(
			'ob-ai-seo-blog-admin',
			OB_AI_SEO_BLOG_URL . 'assets/admin.css',
			array(),
			OB_AI_SEO_BLOG_VERSION
		);
		wp_enqueue_script(
			'ob-ai-seo-blog-admin',
			OB_AI_SEO_BLOG_URL . 'assets/admin.js',
			array(),
			OB_AI_SEO_BLOG_VERSION,
			true
		);
	}

	/**
	 * @return array<int, array{keyword:string, publish_local:?string}>
	 */
	private static function parse_keyword_items_from_request(): array {
		$items = array();
		if ( ! empty( $_POST['ob_ai_rows'] ) && is_array( $_POST['ob_ai_rows'] ) ) {
			foreach ( wp_unslash( $_POST['ob_ai_rows'] ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$keyword = sanitize_text_field( $row['keyword'] ?? '' );
				if ( '' === $keyword ) {
					continue;
				}
				$date   = sanitize_text_field( $row['date'] ?? '' );
				$time   = sanitize_text_field( $row['time'] ?? '09:00' );
				$local  = null;
				if ( $date ) {
					$local = trim( $date . ' ' . ( $time ?: '09:00' ) );
					$local = str_replace( 'T', ' ', $local );
					if ( 16 === strlen( $local ) ) {
						$local .= ':00';
					}
				}
				$items[] = array(
					'keyword'       => $keyword,
					'publish_local' => $local,
				);
			}
		}

		if ( empty( $items ) ) {
			$raw   = isset( $_POST['keywords'] ) ? wp_unslash( $_POST['keywords'] ) : '';
			$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$keyword       = $line;
				$publish_local = null;
				if ( false !== strpos( $line, '|' ) ) {
					$parts         = array_map( 'trim', explode( '|', $line, 2 ) );
					$keyword       = $parts[0];
					$publish_local = $parts[1] ?? null;
				}
				if ( '' === $keyword ) {
					continue;
				}
				$items[] = array(
					'keyword'       => $keyword,
					'publish_local' => $publish_local,
				);
			}
		}

		return $items;
	}

	public static function handle_generate(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'ob-ai-seo-blog' ) );
		}
		check_admin_referer( 'ob_ai_seo_blog_generate' );

		$items = self::parse_keyword_items_from_request();
		if ( empty( $items ) ) {
			wp_safe_redirect( add_query_arg( 'ob_ai_error', 'empty', admin_url( 'admin.php?page=ob-ai-seo-blog' ) ) );
			exit;
		}

		$mode = sanitize_text_field( wp_unslash( $_POST['publish_mode'] ?? 'immediate' ) );
		if ( ! in_array( $mode, array( 'immediate', 'draft', 'scheduled' ), true ) ) {
			$mode = 'immediate';
		}

		$unit  = sanitize_text_field( wp_unslash( $_POST['interval_unit'] ?? 'day' ) );
		$mult  = array(
			'minute' => 1,
			'hour'   => 60,
			'day'    => 1440,
		);
		$interval = max( 1, absint( $_POST['interval_value'] ?? 1 ) ) * ( $mult[ $unit ] ?? 1440 );

		$default_post_status = 'publish';
		$schedule_start      = null;
		if ( 'draft' === $mode ) {
			$default_post_status = 'draft';
		} elseif ( 'scheduled' === $mode ) {
			$default_post_status = 'future';
			if ( ! empty( $_POST['schedule_start'] ) ) {
				$schedule_start = sanitize_text_field( wp_unslash( $_POST['schedule_start'] ) );
				$schedule_start = str_replace( 'T', ' ', $schedule_start );
				if ( 16 === strlen( $schedule_start ) ) {
					$schedule_start .= ':00';
				}
			}
		}

		$batch_id = OB_AI_Queue::enqueue_batch(
			$items,
			'scheduled' === $mode ? $schedule_start : null,
			$interval,
			$default_post_status
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'ob-ai-seo-blog',
					'batch_id' => $batch_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param object $row Queue row.
	 * @return array{key:string, label:string}
	 */
	public static function row_display_status( $row ): array {
		if ( 'processing' === $row->status ) {
			return array(
				'key'   => 'creating',
				'label' => __( 'Oluşturuluyor', 'ob-ai-seo-blog' ),
			);
		}
		if ( 'failed' === $row->status ) {
			return array(
				'key'   => 'error',
				'label' => __( 'Hatalı', 'ob-ai-seo-blog' ),
			);
		}
		if ( 'pending' === $row->status ) {
			return array(
				'key'   => 'queued',
				'label' => __( 'Kuyrukta', 'ob-ai-seo-blog' ),
			);
		}
		if ( 'done' === $row->status && $row->post_id ) {
			$ps = get_post_status( (int) $row->post_id );
			if ( 'future' === $ps ) {
				return array(
					'key'   => 'planned',
					'label' => __( 'Planlandı', 'ob-ai-seo-blog' ),
				);
			}
			if ( 'draft' === $ps ) {
				return array(
					'key'   => 'draft',
					'label' => __( 'Taslak', 'ob-ai-seo-blog' ),
				);
			}
			return array(
				'key'   => 'live',
				'label' => __( 'Yayında', 'ob-ai-seo-blog' ),
			);
		}
		return array(
			'key'   => 'queued',
			'label' => __( 'Tamamlandı', 'ob-ai-seo-blog' ),
		);
	}

	/**
	 * @param array<int, object> $rows Rows.
	 */
	public static function render_queue_table( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p class="ob-ai-empty">' . esc_html__( 'Henüz kuyruk kaydı yok. Sol taraftan içerik üretin.', 'ob-ai-seo-blog' ) . '</p>';
			return;
		}
		?>
		<table class="ob-ai-queue-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Anahtar kelime', 'ob-ai-seo-blog' ); ?></th>
					<th><?php esc_html_e( 'Durum', 'ob-ai-seo-blog' ); ?></th>
					<th><?php esc_html_e( 'Yayın tarihi', 'ob-ai-seo-blog' ); ?></th>
					<th><?php esc_html_e( 'İşlem', 'ob-ai-seo-blog' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $rows as $row ) :
					$st      = self::row_display_status( $row );
					$date_ts = $row->publish_at_gmt ? strtotime( $row->publish_at_gmt . ' UTC' ) : false;
					?>
					<tr>
						<td><?php echo esc_html( $row->focus_keyword ); ?></td>
						<td>
							<span class="ob-ai-badge ob-ai-badge--<?php echo esc_attr( $st['key'] ); ?>"><?php echo esc_html( $st['label'] ); ?></span>
							<?php if ( 'creating' === $st['key'] ) : ?>
								<div class="ob-ai-progress" aria-hidden="true"><span></span></div>
							<?php endif; ?>
							<?php if ( $row->error_message ) : ?>
								<span class="ob-ai-row-error"><?php echo esc_html( $row->error_message ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							if ( $date_ts ) {
								echo esc_html( wp_date( 'd M Y H:i', $date_ts ) );
							} else {
								echo '—';
							}
							?>
						</td>
						<td class="ob-ai-actions">
							<?php if ( $row->post_id ) : ?>
								<a href="<?php echo esc_url( get_permalink( (int) $row->post_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Görüntüle', 'ob-ai-seo-blog' ); ?></a>
								<a href="<?php echo esc_url( get_edit_post_link( (int) $row->post_id ) ); ?>"><?php esc_html_e( 'Düzenle', 'ob-ai-seo-blog' ); ?></a>
							<?php elseif ( 'failed' === $row->status ) : ?>
								<span class="ob-ai-text-muted"><?php esc_html_e( 'Yeniden kuyruğa almak için kelimeyi tekrar ekleyin.', 'ob-ai-seo-blog' ); ?></span>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function render_generator(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return;
		}

		$stats    = OB_AI_Queue::dashboard_stats();
		$batch_id = isset( $_GET['batch_id'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_id'] ) ) : '';
		$rows     = $batch_id ? OB_AI_Queue::get_batch_rows( $batch_id ) : OB_AI_Queue::recent_queue_rows( 12 );

		?>
		<div class="wrap ob-ai-app">
			<div class="ob-ai-header">
				<div>
					<h1><?php esc_html_e( 'AI SEO İçerik Üretimi', 'ob-ai-seo-blog' ); ?></h1>
					<p class="ob-ai-lead">
						<?php esc_html_e( 'Anahtar kelimelerinizi ekleyin, SEO uyumlu içerikler oluşturun ve yayın sürecini yönetin.', 'ob-ai-seo-blog' ); ?>
					</p>
				</div>
				<button type="button" class="ob-ai-btn-help" id="ob-ai-help-btn"><?php esc_html_e( 'Nasıl çalışır?', 'ob-ai-seo-blog' ); ?></button>
			</div>

			<?php if ( isset( $_GET['ob_ai_error'] ) && 'empty' === $_GET['ob_ai_error'] ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'En az bir anahtar kelime girin.', 'ob-ai-seo-blog' ); ?></p></div>
			<?php endif; ?>

			<div class="ob-ai-stats">
				<div class="ob-ai-stat">
					<div class="ob-ai-stat-label"><?php esc_html_e( 'Üretilen', 'ob-ai-seo-blog' ); ?></div>
					<div class="ob-ai-stat-value"><?php echo esc_html( (string) $stats['produced'] ); ?></div>
				</div>
				<div class="ob-ai-stat ob-ai-stat--queue">
					<div class="ob-ai-stat-label"><?php esc_html_e( 'Kuyrukta', 'ob-ai-seo-blog' ); ?></div>
					<div class="ob-ai-stat-value"><?php echo esc_html( (string) $stats['queued'] ); ?></div>
				</div>
				<div class="ob-ai-stat ob-ai-stat--planned">
					<div class="ob-ai-stat-label"><?php esc_html_e( 'Planlanan', 'ob-ai-seo-blog' ); ?></div>
					<div class="ob-ai-stat-value"><?php echo esc_html( (string) $stats['planned'] ); ?></div>
				</div>
				<div class="ob-ai-stat ob-ai-stat--failed">
					<div class="ob-ai-stat-label"><?php esc_html_e( 'Hatalı', 'ob-ai-seo-blog' ); ?></div>
					<div class="ob-ai-stat-value"><?php echo esc_html( (string) $stats['failed'] ); ?></div>
				</div>
			</div>

			<div class="ob-ai-layout">
				<div class="ob-ai-panel">
					<h2><?php esc_html_e( 'Yeni İçerik Üret', 'ob-ai-seo-blog' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ob-ai-generate-form">
						<input type="hidden" name="action" value="ob_ai_seo_blog_generate" />
						<?php wp_nonce_field( 'ob_ai_seo_blog_generate' ); ?>
						<div id="ob-ai-rows-hidden"></div>

						<table class="ob-ai-kw-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Anahtar kelime', 'ob-ai-seo-blog' ); ?></th>
									<th><?php esc_html_e( 'Tarih', 'ob-ai-seo-blog' ); ?></th>
									<th><?php esc_html_e( 'Saat', 'ob-ai-seo-blog' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody id="ob-ai-kw-rows">
								<tr class="ob-ai-kw-row">
									<td class="ob-ai-col-keyword"><input type="text" class="ob-ai-kw-input" placeholder="<?php esc_attr_e( 'örnek anahtar kelime', 'ob-ai-seo-blog' ); ?>" /></td>
									<td><input type="date" class="ob-ai-date-input" /></td>
									<td><input type="time" class="ob-ai-time-input" value="09:00" /></td>
									<td><button type="button" class="ob-ai-btn-icon ob-ai-remove-row" title="<?php esc_attr_e( 'Sil', 'ob-ai-seo-blog' ); ?>">&times;</button></td>
								</tr>
							</tbody>
						</table>
						<button type="button" class="ob-ai-btn-add" id="ob-ai-add-row">+ <?php esc_html_e( 'Anahtar Kelime Ekle', 'ob-ai-seo-blog' ); ?></button>

						<fieldset class="ob-ai-publish-mode">
							<legend><?php esc_html_e( 'Yayın ayarları', 'ob-ai-seo-blog' ); ?></legend>
							<label class="ob-ai-radio">
								<input type="radio" name="publish_mode" value="immediate" checked />
								<?php esc_html_e( 'Hemen yayınla', 'ob-ai-seo-blog' ); ?>
							</label>
							<label class="ob-ai-radio">
								<input type="radio" name="publish_mode" value="draft" />
								<?php esc_html_e( 'Taslak olarak kaydet', 'ob-ai-seo-blog' ); ?>
							</label>
							<label class="ob-ai-radio">
								<input type="radio" name="publish_mode" value="scheduled" />
								<?php esc_html_e( 'Planlanan tarihte yayınla', 'ob-ai-seo-blog' ); ?>
							</label>
							<div class="ob-ai-schedule-batch" hidden>
								<label for="ob-ai-schedule-start"><?php esc_html_e( 'İlk yayın (satırda tarih yoksa)', 'ob-ai-seo-blog' ); ?></label><br />
								<input type="datetime-local" name="schedule_start" id="ob-ai-schedule-start" />
							</div>
							<div class="ob-ai-interval">
								<span><?php esc_html_e( 'İçerikler arası yayın aralığı', 'ob-ai-seo-blog' ); ?></span>
								<input type="number" name="interval_value" value="1" min="1" step="1" />
								<select name="interval_unit">
									<option value="day"><?php esc_html_e( 'Gün', 'ob-ai-seo-blog' ); ?></option>
									<option value="hour"><?php esc_html_e( 'Saat', 'ob-ai-seo-blog' ); ?></option>
									<option value="minute"><?php esc_html_e( 'Dakika', 'ob-ai-seo-blog' ); ?></option>
								</select>
							</div>
						</fieldset>

						<button type="submit" class="ob-ai-submit"><?php esc_html_e( 'İçerikleri Üret', 'ob-ai-seo-blog' ); ?></button>
					</form>
				</div>

				<div class="ob-ai-panel">
					<div class="ob-ai-panel-head">
						<h2><?php esc_html_e( 'İçerik kuyruğu', 'ob-ai-seo-blog' ); ?></h2>
						<a class="ob-ai-link-refresh" href="<?php echo esc_url( admin_url( 'admin.php?page=ob-ai-seo-blog' . ( $batch_id ? '&batch_id=' . rawurlencode( $batch_id ) : '' ) ) ); ?>"><?php esc_html_e( 'Yenile', 'ob-ai-seo-blog' ); ?></a>
					</div>
					<?php if ( $batch_id ) : ?>
						<p class="description" style="margin-top:0"><?php esc_html_e( 'Batch:', 'ob-ai-seo-blog' ); ?> <code><?php echo esc_html( $batch_id ); ?></code></p>
					<?php endif; ?>
					<?php self::render_queue_table( $rows ); ?>
					<p class="description" style="margin-top:12px">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=ob-ai-seo-blog-queue' ) ); ?>"><?php esc_html_e( 'Tüm kuyruğu gör →', 'ob-ai-seo-blog' ); ?></a>
					</p>
				</div>
			</div>

			<dialog class="ob-ai-help-dialog" id="ob-ai-help-dialog">
				<div class="ob-ai-help-inner">
					<h3><?php esc_html_e( 'Nasıl çalışır?', 'ob-ai-seo-blog' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Anahtar kelime satırlarını doldurun; isteğe bağlı tarih/saat ekleyin.', 'ob-ai-seo-blog' ); ?></li>
						<li><?php esc_html_e( 'Yayın modunu seçin (hemen, taslak veya planlı).', 'ob-ai-seo-blog' ); ?></li>
						<li><?php esc_html_e( 'İçerikleri Üret — işlem arka planda dakikada bir işlenir.', 'ob-ai-seo-blog' ); ?></li>
						<li><?php esc_html_e( 'Kuyruk tablosundan durumu takip edin; Rank Math alanları otomatik dolar.', 'ob-ai-seo-blog' ); ?></li>
					</ol>
					<button type="button" class="ob-ai-help-close" id="ob-ai-help-close"><?php esc_html_e( 'Tamam', 'ob-ai-seo-blog' ); ?></button>
				</div>
			</dialog>
		</div>
		<?php
	}

	public static function render_queue(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return;
		}
		$rows = OB_AI_Queue::recent_queue_rows( 100 );
		?>
		<div class="wrap ob-ai-app">
			<div class="ob-ai-header">
				<div>
					<h1><?php esc_html_e( 'İçerik kuyruğu', 'ob-ai-seo-blog' ); ?></h1>
					<p class="ob-ai-lead"><?php esc_html_e( 'Tüm üretim işlerinin durumu.', 'ob-ai-seo-blog' ); ?></p>
				</div>
				<a class="ob-ai-btn-help" href="<?php echo esc_url( admin_url( 'admin.php?page=ob-ai-seo-blog' ) ); ?>"><?php esc_html_e( '← İçerik üret', 'ob-ai-seo-blog' ); ?></a>
			</div>
			<div class="ob-ai-panel">
				<?php self::render_queue_table( $rows ); ?>
			</div>
		</div>
		<?php
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = OB_AI_Settings::get();
		?>
		<div class="wrap ob-ai-wrap">
			<h1><?php esc_html_e( 'AI SEO Blog Ayarları', 'ob-ai-seo-blog' ); ?></h1>
			<?php
			$usage = OB_AI_SaaS_Client::get_usage();
			if ( ! is_wp_error( $usage ) && ! empty( $usage['usage'] ) ) :
				?>
				<div class="notice notice-info">
					<p>
						<strong><?php esc_html_e( 'SaaS kotası', 'ob-ai-seo-blog' ); ?>:</strong>
						<?php
						printf(
							/* translators: 1: package name 2: used 3: limit */
							esc_html__( 'Paket %1$s — bu ay %2$s / %3$s token', 'ob-ai-seo-blog' ),
							esc_html( $usage['package']['name'] ?? '' ),
							esc_html( number_format_i18n( (int) ( $usage['usage']['tokens_used'] ?? 0 ) ) ),
							esc_html( number_format_i18n( (int) ( $usage['usage']['tokens_limit'] ?? 0 ) ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'ob_ai_seo_blog_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="saas_api_base"><?php esc_html_e( 'SaaS API adresi', 'ob-ai-seo-blog' ); ?></label></th>
						<td>
							<input type="url" id="saas_api_base" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[saas_api_base]" value="<?php echo esc_attr( $s['saas_api_base'] ); ?>" class="regular-text" placeholder="https://api.ornek.com" />
							<p class="description"><?php esc_html_e( 'Müşteri eklentisi yalnızca bu adrese bağlanır; OpenAI anahtarı sunucunuzda kalır.', 'ob-ai-seo-blog' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="license_key"><?php esc_html_e( 'Lisans anahtarı', 'ob-ai-seo-blog' ); ?></label></th>
						<td>
							<input type="text" id="license_key" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[license_key]" value="<?php echo esc_attr( $s['license_key'] ); ?>" class="regular-text code" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Önerilen: define(\'F2F_LICENSE_KEY\', \'OBAI-...\'); ve define(\'F2F_SAAS_API_BASE\', \'https://api.f2fbilisim.com\');', 'ob-ai-seo-blog' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="openai_api_key"><?php esc_html_e( 'OpenAI API Key (geliştirici)', 'ob-ai-seo-blog' ); ?></label></th>
						<td>
							<input type="password" id="openai_api_key" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[openai_api_key]" value="<?php echo esc_attr( $s['openai_api_key'] ); ?>" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Yalnızca SaaS kapalıyken (lisans/API boş) doğrudan OpenAI için kullanılır.', 'ob-ai-seo-blog' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="text_model"><?php esc_html_e( 'Metin modeli', 'ob-ai-seo-blog' ); ?></label></th>
						<td><input type="text" id="text_model" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[text_model]" value="<?php echo esc_attr( $s['text_model'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="image_model"><?php esc_html_e( 'Görsel modeli', 'ob-ai-seo-blog' ); ?></label></th>
						<td><input type="text" id="image_model" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[image_model]" value="<?php echo esc_attr( $s['image_model'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="language"><?php esc_html_e( 'İçerik dili', 'ob-ai-seo-blog' ); ?></label></th>
						<td><input type="text" id="language" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[language]" value="<?php echo esc_attr( $s['language'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="target_words"><?php esc_html_e( 'Hedef kelime sayısı', 'ob-ai-seo-blog' ); ?></label></th>
						<td><input type="number" id="target_words" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[target_words]" value="<?php echo esc_attr( (string) $s['target_words'] ); ?>" min="400" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="default_category"><?php esc_html_e( 'Varsayılan kategori ID', 'ob-ai-seo-blog' ); ?></label></th>
						<td><input type="number" id="default_category" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[default_category]" value="<?php echo esc_attr( (string) $s['default_category'] ); ?>" min="0" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Görseller', 'ob-ai-seo-blog' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[generate_images]" value="1" <?php checked( ! empty( $s['generate_images'] ) ); ?> /> <?php esc_html_e( 'Kapak + inline görsel üret', 'ob-ai-seo-blog' ); ?></label><br />
							<label for="inline_image_count"><?php esc_html_e( 'Makale içi görsel adedi (0–3)', 'ob-ai-seo-blog' ); ?></label>
							<input type="number" id="inline_image_count" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[inline_image_count]" value="<?php echo esc_attr( (string) $s['inline_image_count'] ); ?>" min="0" max="3" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="site_context"><?php esc_html_e( 'Site bağlamı (prompt)', 'ob-ai-seo-blog' ); ?></label></th>
						<td>
							<textarea id="site_context" name="<?php echo esc_attr( OB_AI_Settings::OPTION_KEY ); ?>[site_context]" rows="5" class="large-text"><?php echo esc_textarea( $s['site_context'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Ajans adı, hizmetler, Bursa odaklı ton vb. tüm makalelere eklenir.', 'ob-ai-seo-blog' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
