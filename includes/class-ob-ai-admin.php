<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OB_AI_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ob_ai_seo_blog_generate', array( __CLASS__, 'handle_generate' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'AI SEO Blog', 'ob-ai-seo-blog' ),
			__( 'AI SEO Blog', 'ob-ai-seo-blog' ),
			'publish_posts',
			'ob-ai-seo-blog',
			array( __CLASS__, 'render_generator' ),
			'dashicons-edit-large',
			58
		);

		add_submenu_page(
			'ob-ai-seo-blog',
			__( 'Toplu Üret', 'ob-ai-seo-blog' ),
			__( 'Toplu Üret', 'ob-ai-seo-blog' ),
			'publish_posts',
			'ob-ai-seo-blog',
			array( __CLASS__, 'render_generator' )
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

	public static function handle_generate(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'ob-ai-seo-blog' ) );
		}
		check_admin_referer( 'ob_ai_seo_blog_generate' );

		$raw   = isset( $_POST['keywords'] ) ? wp_unslash( $_POST['keywords'] ) : '';
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$items = array();
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
				if ( $publish_local ) {
					$publish_local = str_replace( 'T', ' ', $publish_local );
					if ( 16 === strlen( $publish_local ) ) {
						$publish_local .= ':00';
					}
				}
			}
			if ( '' === $keyword ) {
				continue;
			}
			$items[] = array(
				'keyword'       => $keyword,
				'publish_local' => $publish_local,
			);
		}

		if ( empty( $items ) ) {
			wp_safe_redirect( add_query_arg( 'ob_ai_error', 'empty', admin_url( 'admin.php?page=ob-ai-seo-blog' ) ) );
			exit;
		}

		$schedule_enabled = ! empty( $_POST['schedule_enabled'] );
		$schedule_start   = '';
		if ( $schedule_enabled && ! empty( $_POST['schedule_start'] ) ) {
			$schedule_start = sanitize_text_field( wp_unslash( $_POST['schedule_start'] ) );
			$schedule_start = str_replace( 'T', ' ', $schedule_start );
			if ( strlen( $schedule_start ) === 16 ) {
				$schedule_start .= ':00';
			}
		}

		$interval = max( 1, absint( $_POST['interval_minutes'] ?? 60 ) );

		$batch_id = OB_AI_Queue::enqueue_batch(
			$items,
			$schedule_enabled ? $schedule_start : null,
			$interval
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

	public static function render_generator(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return;
		}

		$batch_id = isset( $_GET['batch_id'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_id'] ) ) : '';
		$rows     = $batch_id ? OB_AI_Queue::get_batch_rows( $batch_id ) : array();
		$batches  = OB_AI_Queue::recent_batches( 8 );

		?>
		<div class="wrap ob-ai-wrap">
			<h1><?php esc_html_e( 'OpenAI Toplu SEO Blog', 'ob-ai-seo-blog' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Her satıra bir odak anahtar kelime yazın. Üretim kuyruğa alınır; hazır olanlar anında yayınlanır veya seçtiğiniz tarihte WordPress planlı yayın olarak kaydedilir. Rank Math meta alanları otomatik doldurulur.', 'ob-ai-seo-blog' ); ?>
			</p>

			<?php if ( isset( $_GET['ob_ai_error'] ) && 'empty' === $_GET['ob_ai_error'] ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'En az bir anahtar kelime girin.', 'ob-ai-seo-blog' ); ?></p></div>
			<?php endif; ?>

			<div class="ob-ai-grid">
				<div class="ob-ai-card">
					<h2><?php esc_html_e( 'Anahtar kelimeler', 'ob-ai-seo-blog' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ob_ai_seo_blog_generate" />
						<?php wp_nonce_field( 'ob_ai_seo_blog_generate' ); ?>

						<label for="ob-ai-keywords" class="screen-reader-text"><? esc_html_e( 'Anahtar kelimeler', 'ob-ai-seo-blog' ); ?></label>
						<textarea name="keywords" id="ob-ai-keywords" rows="12" class="large-text code" placeholder="<?php esc_attr_e( "Bursa web tasarım\nBursa e-ticaret | 2026-10-15 09:00", 'ob-ai-seo-blog' ); ?>"></textarea>
						<p class="description"><?php esc_html_e( 'İsteğe bağlı: satır sonuna | YYYY-MM-DD HH:MM ekleyerek o makaleyi ayrı planlayabilirsiniz.', 'ob-ai-seo-blog' ); ?></p>

						<fieldset class="ob-ai-schedule">
							<legend><?php esc_html_e( 'Yayın planı', 'ob-ai-seo-blog' ); ?></legend>
							<label>
								<input type="checkbox" name="schedule_enabled" id="ob-ai-schedule-enabled" value="1" />
								<?php esc_html_e( 'Planla (ilk satır seçilen saatte, sonrakiler aralık kadar gecikmeli)', 'ob-ai-seo-blog' ); ?>
							</label>
							<div class="ob-ai-schedule-fields" hidden>
								<p>
									<label for="ob-ai-schedule-start"><?php esc_html_e( 'Başlangıç tarihi ve saati', 'ob-ai-seo-blog' ); ?></label><br />
									<input type="datetime-local" name="schedule_start" id="ob-ai-schedule-start" />
								</p>
								<p>
									<label for="ob-ai-interval"><?php esc_html_e( 'Makaleler arası (dakika)', 'ob-ai-seo-blog' ); ?></label><br />
									<input type="number" name="interval_minutes" id="ob-ai-interval" value="1440" min="1" step="1" class="small-text" />
									<span class="description"><?php esc_html_e( 'Örn. 1440 = her gün bir makale', 'ob-ai-seo-blog' ); ?></span>
								</p>
							</div>
							<p class="description">
								<?php esc_html_e( 'Plan kapalıyken her makale üretildiği anda yayınlanır (taslak yok). Plan açıkken WordPress “planlanmış” durumuna yazar; saat gelince otomatik yayınlanır.', 'ob-ai-seo-blog' ); ?>
							</p>
						</fieldset>

						<?php submit_button( __( 'Toplu üret', 'ob-ai-seo-blog' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

				<div class="ob-ai-card">
					<h2><?php esc_html_e( 'Kuyruk durumu', 'ob-ai-seo-blog' ); ?></h2>
					<?php if ( $batch_id && $rows ) : ?>
						<p><strong><?php esc_html_e( 'Batch:', 'ob-ai-seo-blog' ); ?></strong> <code><?php echo esc_html( $batch_id ); ?></code></p>
						<p class="description"><?php esc_html_e( 'Sayfa birkaç dakika içinde yenilendiğinde ilerlemeyi görebilirsiniz. İşlem arka planda dakikada bir çalışır.', 'ob-ai-seo-blog' ); ?></p>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Anahtar kelime', 'ob-ai-seo-blog' ); ?></th>
									<th><?php esc_html_e( 'Yayın (site saati)', 'ob-ai-seo-blog' ); ?></th>
									<th><?php esc_html_e( 'Durum', 'ob-ai-seo-blog' ); ?></th>
									<th><?php esc_html_e( 'Yazı', 'ob-ai-seo-blog' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row->focus_keyword ); ?></td>
										<td><?php echo esc_html( get_date_from_gmt( $row->publish_at_gmt, 'Y-m-d H:i' ) ); ?></td>
										<td><span class="ob-ai-status ob-ai-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status ); ?></span>
											<?php if ( $row->error_message ) : ?>
												<br /><small><?php echo esc_html( $row->error_message ); ?></small>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( $row->post_id ) : ?>
												<a href="<?php echo esc_url( get_edit_post_link( (int) $row->post_id ) ); ?>"><?php esc_html_e( 'Düzenle', 'ob-ai-seo-blog' ); ?></a>
											<?php else : ?>
												—
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'Henüz batch seçilmedi. Üretim başlattıktan sonra burada ilerleme listelenir.', 'ob-ai-seo-blog' ); ?></p>
					<?php endif; ?>

					<?php if ( $batches ) : ?>
						<h3><?php esc_html_e( 'Son batch\'ler', 'ob-ai-seo-blog' ); ?></h3>
						<ul class="ob-ai-batch-list">
							<?php foreach ( $batches as $b ) : ?>
								<li>
									<a href="<?php echo esc_url( add_query_arg( 'batch_id', $b->batch_id, admin_url( 'admin.php?page=ob-ai-seo-blog' ) ) ); ?>">
										<code><?php echo esc_html( $b->batch_id ); ?></code>
									</a>
									— <?php echo esc_html( (int) $b->done_count . '/' . (int) $b->total ); ?>
									<?php if ( (int) $b->failed_count > 0 ) : ?>
										<span class="ob-ai-failed">(<?php echo esc_html( (int) $b->failed_count ); ?> failed)</span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
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
							<p class="description"><?php esc_html_e( 'Alternatif: define(\'OB_AI_LICENSE_KEY\', \'OBAI-...\');', 'ob-ai-seo-blog' ); ?></p>
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
