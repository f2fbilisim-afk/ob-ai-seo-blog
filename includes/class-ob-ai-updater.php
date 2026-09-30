<?php
/**
 * GitHub manifest ile WordPress eklenti güncellemesi.
 *
 * @package OB_AI_SEO_Blog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OB_AI_Updater {

	const CACHE_KEY = 'ob_ai_seo_blog_update_info';
	const CACHE_TTL = 30 * MINUTE_IN_SECONDS;
	const SLUG       = 'ob-ai-seo-blog';

	/** @return array<int, string> */
	public static function manifest_urls(): array {
		if ( defined( 'OB_AI_UPDATE_JSON' ) && OB_AI_UPDATE_JSON ) {
			return array( (string) OB_AI_UPDATE_JSON );
		}
		$repo   = defined( 'OB_AI_GITHUB_REPO' ) && OB_AI_GITHUB_REPO ? trim( (string) OB_AI_GITHUB_REPO, '/' ) : 'f2fbilisim-afk/ob-ai-seo-blog';
		$branch = defined( 'OB_AI_GITHUB_BRANCH' ) && OB_AI_GITHUB_BRANCH ? (string) OB_AI_GITHUB_BRANCH : 'main';
		$api = defined( 'F2F_SAAS_API_BASE' ) && F2F_SAAS_API_BASE
			? untrailingslashit( (string) F2F_SAAS_API_BASE )
			: 'https://api.f2fbilisim.com';

		return array(
			$api . '/api/v1/wordpress/ob-ai-seo-blog/update',
			'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode( $branch ) . '/updates/ob-ai-seo-blog.json',
			'https://cdn.jsdelivr.net/gh/' . $repo . '@' . rawurlencode( $branch ) . '/updates/ob-ai-seo-blog.json',
		);
	}

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_fresh' ) );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
		add_action( 'after_plugin_row_' . plugin_basename( OB_AI_SEO_BLOG_FILE ), array( __CLASS__, 'plugin_row_notice' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_plugins_notice' ) );
	}

	/**
	 * @param array<int, string> $links
	 * @param string             $file
	 * @return array<int, string>
	 */
	public static function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( OB_AI_SEO_BLOG_FILE ) !== $file ) {
			return $links;
		}
		$remote = self::remote_info();
		if ( $remote && version_compare( $remote['version'], OB_AI_SEO_BLOG_VERSION, '>' ) ) {
			$links[] = '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '"><strong>' . esc_html(
				sprintf(
					/* translators: %s version */
					__( 'Güncelleme: %s', 'ob-ai-seo-blog' ),
					$remote['version']
				)
			) . '</strong></a>';
		}
		$ver = esc_html__( 'Sürüm', 'ob-ai-seo-blog' ) . ' ' . esc_html( OB_AI_SEO_BLOG_VERSION );
		if ( defined( 'OB_AI_SEO_BLOG_BUILD' ) ) {
			$ver .= ' · ' . esc_html( OB_AI_SEO_BLOG_BUILD );
		}
		$links[] = '<span>' . $ver . '</span>';
		$links[] = '<code style="font-size:11px">' . esc_html( OB_AI_SEO_BLOG_FILE ) . '</code>';
		return $links;
	}

	public static function admin_plugins_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
		$remote = self::remote_info( true );
		if ( ! $remote || version_compare( $remote['version'], OB_AI_SEO_BLOG_VERSION, '<=' ) ) {
			return;
		}
		$zip = $remote['download_url'];
		echo '<div class="notice notice-warning"><p><strong>OB AI SEO Blog:</strong> ';
		printf(
			/* translators: 1: current 2: new 3: download url */
			esc_html__( 'Kurulu sürüm %1$s — yeni sürüm %2$s. WordPress güncellemesi görünmüyorsa zip indirip yükleyin: %3$s', 'ob-ai-seo-blog' ),
			esc_html( OB_AI_SEO_BLOG_VERSION ),
			esc_html( $remote['version'] ),
			''
		);
		echo ' <a href="' . esc_url( $zip ) . '" target="_blank" rel="noopener">' . esc_html__( 'İndir', 'ob-ai-seo-blog' ) . '</a>';
		echo '</p></div>';
	}

	/** @param mixed $transient */
	public static function inject_fresh( $transient ) {
		self::clear_cache();
		return self::inject( $transient );
	}

	/** @param mixed $transient */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$plugin = plugin_basename( OB_AI_SEO_BLOG_FILE );
		$remote = self::remote_info();
		if ( ! $remote ) {
			return $transient;
		}
		$current = OB_AI_SEO_BLOG_VERSION;
		if ( ! empty( $transient->checked[ $plugin ] ) ) {
			$current = (string) $transient->checked[ $plugin ];
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( version_compare( $remote['version'], $current, '<=' ) ) {
			return $transient;
		}
		$transient->response[ $plugin ] = (object) array(
			'slug'        => self::SLUG,
			'plugin'      => $plugin,
			'new_version' => $remote['version'],
			'url'         => $remote['homepage'],
			'package'     => $remote['download_url'],
			'tested'      => $remote['tested'],
			'requires_php'  => $remote['requires_php'],
		);
		return $transient;
	}

	/**
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public static function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$remote = self::remote_info();
		if ( ! $remote ) {
			return $result;
		}
		return (object) array(
			'name'          => $remote['name'],
			'slug'          => self::SLUG,
			'version'       => $remote['version'],
			'author'        => '<a href="https://www.f2fbilisim.com">F2F Bilişim</a>',
			'homepage'      => $remote['homepage'],
			'download_link' => $remote['download_url'],
			'sections'      => array(
				'description' => $remote['name'],
				'changelog'   => $remote['changelog'],
			),
			'tested'        => $remote['tested'],
			'requires_php'  => $remote['requires_php'],
		);
	}

	/** @return array<string, mixed>|null */
	public static function remote_info( bool $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
				return $cached;
			}
		}
		foreach ( self::manifest_urls() as $url ) {
			$res = wp_remote_get(
				$url,
				array(
					'timeout' => 12,
					'headers' => array(
						'Accept'     => 'application/json',
						'User-Agent' => 'OB-AI-SEO-Blog/' . OB_AI_SEO_BLOG_VERSION,
					),
				)
			);
			if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
				continue;
			}
			$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
				continue;
			}
			$info = array(
				'name'         => isset( $data['name'] ) ? (string) $data['name'] : 'OB AI SEO Blog',
				'version'      => (string) $data['version'],
				'download_url' => esc_url_raw( (string) $data['download_url'] ),
				'tested'       => isset( $data['tested'] ) ? (string) $data['tested'] : '6.7',
				'requires_php' => isset( $data['requires_php'] ) ? (string) $data['requires_php'] : '7.4',
				'homepage'     => isset( $data['homepage'] ) ? esc_url_raw( (string) $data['homepage'] ) : 'https://github.com/f2fbilisim-afk/ob-ai-seo-blog',
				'changelog'    => isset( $data['changelog'] ) ? (string) $data['changelog'] : '',
			);
			set_transient( self::CACHE_KEY, $info, self::CACHE_TTL );
			return $info;
		}
		return null;
	}

	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * @param string $plugin_file
	 * @param array  $plugin_data
	 */
	public static function plugin_row_notice( $plugin_file, $plugin_data ): void {
		if ( plugin_basename( OB_AI_SEO_BLOG_FILE ) !== $plugin_file ) {
			return;
		}
		$remote = self::remote_info();
		if ( ! $remote || version_compare( $remote['version'], OB_AI_SEO_BLOG_VERSION, '<=' ) ) {
			return;
		}
		echo '<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange">';
		echo '<div class="update-message notice inline notice-warning notice-alt"><p>';
		printf(
			/* translators: 1: version, 2: link */
			esc_html__( 'OB AI SEO Blog güncellemesi mevcut: %1$s. %2$s', 'ob-ai-seo-blog' ),
			esc_html( $remote['version'] ),
			'<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Eklentiler sayfasında Güncelle.', 'ob-ai-seo-blog' ) . '</a>'
		);
		echo '</p></div></td></tr>';
	}
}
