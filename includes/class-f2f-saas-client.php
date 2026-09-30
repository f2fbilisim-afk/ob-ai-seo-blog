<?php
/**
 * F2F merkezi SaaS istemcisi — SEO Blog + AI Chatbot eklentileri için ortak.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class F2F_SaaS_Client {

	public const DEFAULT_API_BASE = 'https://api.f2fbilisim.com';

	/** @var string seo-blog | ai-chatbot */
	private $product;

	/** @var string|null option key for license override */
	private $license_option_key;

	public function __construct( string $product, ?string $license_option_key = null ) {
		$this->product            = $product;
		$this->license_option_key = $license_option_key;
	}

	public function api_base(): string {
		if ( defined( 'F2F_SAAS_API_BASE' ) && F2F_SAAS_API_BASE ) {
			return untrailingslashit( (string) F2F_SAAS_API_BASE );
		}
		if ( $this->license_option_key ) {
			$opts = get_option( $this->license_option_key, array() );
			if ( is_array( $opts ) && ! empty( $opts['saas_api_base'] ) ) {
				return untrailingslashit( (string) $opts['saas_api_base'] );
			}
		}
		return self::DEFAULT_API_BASE;
	}

	public function license_key(): string {
		if ( defined( 'F2F_LICENSE_KEY' ) && F2F_LICENSE_KEY ) {
			return trim( (string) F2F_LICENSE_KEY );
		}
		if ( defined( 'OB_AI_LICENSE_KEY' ) && OB_AI_LICENSE_KEY ) {
			return trim( (string) OB_AI_LICENSE_KEY );
		}
		if ( $this->license_option_key ) {
			$opts = get_option( $this->license_option_key, array() );
			if ( is_array( $opts ) && ! empty( $opts['license_key'] ) ) {
				return trim( (string) $opts['license_key'] );
			}
		}
		return '';
	}

	public function is_configured(): bool {
		return '' !== $this->license_key();
	}

	/**
	 * @return array|WP_Error
	 */
	public function validate_license() {
		return $this->request( 'GET', '/api/v1/license', null );
	}

	/**
	 * @return array|WP_Error
	 */
	public function chat( array $messages, array $extra = array() ) {
		$body = array_merge( array( 'messages' => $messages ), $extra );
		return $this->request( 'POST', '/api/v1/chat', $body );
	}

	/**
	 * @return array|WP_Error
	 */
	public function request( string $method, string $path, ?array $body = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'f2f_no_license',
				__( 'F2F lisans anahtarı tanımlı değil.', 'ob-ai-seo-blog' )
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => 120,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'X-OB-License'  => $this->license_key(),
				'X-OB-Site-Url' => home_url( '/' ),
				'X-OB-Product'  => $this->product,
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url      = $this->api_base() . $path;
		$response = 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = $raw['error']['message'] ?? __( 'F2F API isteği başarısız.', 'ob-ai-seo-blog' );
			return new WP_Error( 'f2f_api', $msg, array( 'status' => $code ) );
		}

		return is_array( $raw ) ? $raw : array();
	}
}
