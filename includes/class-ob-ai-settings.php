<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OB_AI_Settings {

	const OPTION_KEY = 'ob_ai_seo_blog_settings';

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function defaults(): array {
		return array(
			'saas_api_base'      => 'https://api.f2fbilisim.com',
			'license_key'        => '',
			'openai_api_key'     => '',
			'text_model'         => 'gpt-4o-mini',
			'image_model'        => 'gpt-image-1',
			'language'           => 'tr',
			'target_words'       => 1200,
			'default_category'   => 0,
			'generate_images'    => 1,
			'inline_image_count' => 1,
			'site_context'       => '',
		);
	}

	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function get_api_key(): string {
		if ( defined( 'OB_OPENAI_API_KEY' ) && OB_OPENAI_API_KEY ) {
			return (string) OB_OPENAI_API_KEY;
		}
		$s = self::get();
		return (string) ( $s['openai_api_key'] ?? '' );
	}

	public static function register(): void {
		register_setting(
			'ob_ai_seo_blog_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ): array {
		$out  = self::defaults();
		$data = is_array( $input ) ? $input : array();

		$out['saas_api_base'] = esc_url_raw( trim( $data['saas_api_base'] ?? '' ) );
		$out['license_key']   = sanitize_text_field( $data['license_key'] ?? '' );
		if ( isset( $data['openai_api_key'] ) ) {
			$out['openai_api_key'] = sanitize_text_field( $data['openai_api_key'] );
		}
		$out['text_model']         = sanitize_text_field( $data['text_model'] ?? $out['text_model'] );
		$out['image_model']        = sanitize_text_field( $data['image_model'] ?? $out['image_model'] );
		$out['language']           = sanitize_text_field( $data['language'] ?? $out['language'] );
		$out['target_words']       = max( 400, absint( $data['target_words'] ?? $out['target_words'] ) );
		$out['default_category']   = absint( $data['default_category'] ?? 0 );
		$out['generate_images']    = empty( $data['generate_images'] ) ? 0 : 1;
		$out['inline_image_count'] = min( 3, max( 0, absint( $data['inline_image_count'] ?? 1 ) ) );
		$out['site_context']       = sanitize_textarea_field( $data['site_context'] ?? '' );

		return $out;
	}
}
