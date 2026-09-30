<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OB_AI_SaaS_Client {

	private static function base_url(): string {
		$s = OB_AI_Settings::get();
		$url = trim( (string) ( $s['saas_api_base'] ?? '' ) );
		if ( '' === $url ) {
			return '';
		}
		return untrailingslashit( $url );
	}

	private static function license_key(): string {
		if ( defined( 'OB_AI_LICENSE_KEY' ) && OB_AI_LICENSE_KEY ) {
			return (string) OB_AI_LICENSE_KEY;
		}
		$s = OB_AI_Settings::get();
		return trim( (string) ( $s['license_key'] ?? '' ) );
	}

	private static function request_headers(): array {
		$headers = array(
			'Content-Type'  => 'application/json',
			'X-OB-License'  => self::license_key(),
			'X-OB-Site-Url' => home_url( '/' ),
		);
		return $headers;
	}

	public static function is_configured(): bool {
		return '' !== self::base_url() && '' !== self::license_key();
	}

	/**
	 * @return array|WP_Error
	 */
	public static function generate_article( string $focus_keyword, array $settings ) {
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'no_license',
				__( 'SaaS lisans anahtarı ve API adresi tanımlı değil (Ayarlar).', 'ob-ai-seo-blog' )
			);
		}

		$body = array(
			'focus_keyword'      => $focus_keyword,
			'language'           => $settings['language'] ?? 'tr',
			'target_words'       => (int) ( $settings['target_words'] ?? 1200 ),
			'site_context'       => $settings['site_context'] ?? '',
			'inline_image_count' => (int) ( $settings['inline_image_count'] ?? 0 ),
		);

		$response = wp_remote_post(
			self::base_url() . '/api/v1/article',
			array(
				'timeout' => 120,
				'headers' => self::request_headers(),
				'body'    => wp_json_encode( $body ),
			)
		);

		return self::parse_json_response( $response, 'article' );
	}

	/**
	 * @return array{path:string,mime:string}|WP_Error
	 */
	public static function generate_image_file( string $prompt, array $settings ) {
		unset( $settings );
		if ( ! self::is_configured() ) {
			return new WP_Error( 'no_license', __( 'SaaS lisans tanımlı değil.', 'ob-ai-seo-blog' ) );
		}

		$response = wp_remote_post(
			self::base_url() . '/api/v1/image',
			array(
				'timeout' => 180,
				'headers' => self::request_headers(),
				'body'    => wp_json_encode( array( 'prompt' => $prompt ) ),
			)
		);

		$data = self::parse_json_response( $response, 'image' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$b64 = $data['b64_json'] ?? '';
		if ( ! $b64 ) {
			return new WP_Error( 'saas_image_empty', __( 'Görsel verisi alınamadı.', 'ob-ai-seo-blog' ) );
		}

		$binary = base64_decode( $b64, true );
		if ( false === $binary ) {
			return new WP_Error( 'saas_image_decode', __( 'Görsel decode edilemedi.', 'ob-ai-seo-blog' ) );
		}

		$tmp = wp_tempnam( 'ob-ai-image.png' );
		if ( ! $tmp ) {
			return new WP_Error( 'temp_file', __( 'Geçici dosya oluşturulamadı.', 'ob-ai-seo-blog' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $binary );

		return array(
			'path' => $tmp,
			'mime' => $data['mime'] ?? 'image/png',
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function get_usage() {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'no_license', __( 'Lisans yapılandırılmadı.', 'ob-ai-seo-blog' ) );
		}

		$response = wp_remote_get(
			self::base_url() . '/api/v1/usage',
			array(
				'timeout' => 30,
				'headers' => self::request_headers(),
			)
		);

		return self::parse_json_response( $response, 'usage' );
	}

	/**
	 * @param array|WP_Error $response
	 * @return array|WP_Error
	 */
	private static function parse_json_response( $response, string $context ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = $raw['error']['message'] ?? __( 'SaaS API isteği başarısız.', 'ob-ai-seo-blog' );
			return new WP_Error( 'saas_' . $context, $msg, array( 'status' => $code ) );
		}

		if ( 'article' === $context ) {
			$article = $raw['article'] ?? null;
			if ( ! is_array( $article ) || empty( $article['title'] ) || empty( $article['content_html'] ) ) {
				return new WP_Error( 'saas_parse', __( 'Makale yanıtı geçersiz.', 'ob-ai-seo-blog' ) );
			}
			return $article;
		}

		return is_array( $raw ) ? $raw : array();
	}
}
