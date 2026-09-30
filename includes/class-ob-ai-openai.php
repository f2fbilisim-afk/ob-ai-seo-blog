<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OB_AI_OpenAI {

	/**
	 * @return array|WP_Error
	 */
	public static function generate_article( string $focus_keyword, array $settings ) {
		$api_key = OB_AI_Settings::get_api_key();
		if ( ! $api_key ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI API anahtarı tanımlı değil.', 'ob-ai-seo-blog' ) );
		}

		$lang   = $settings['language'] ?? 'tr';
		$words  = (int) ( $settings['target_words'] ?? 1200 );
		$ctx    = trim( (string) ( $settings['site_context'] ?? '' ) );
		$inline = (int) ( $settings['inline_image_count'] ?? 0 );

		$system = 'Sen deneyimli bir SEO içerik yazarısın. Yanıtını YALNIZCA geçerli JSON olarak ver; markdown code fence kullanma. '
			. 'JSON şeması: {"title":"","slug":"","excerpt":"","meta_title":"","meta_description":"","content_html":"","image_prompt":"","inline_image_prompts":[]}. '
			. 'content_html: WordPress için güvenli HTML (h2,h3,p,ul,ol,strong,em,figure,figcaption). '
			. 'Odak anahtar kelime doğal biçimde title, meta, H2 ve gövdede geçsin. meta_description en fazla 155 karakter. '
			. 'inline_image_prompts: içerikte kullanılacak ' . $inline . ' adet görsel için İngilizce DALL-E prompt listesi. '
			. 'image_prompt: kapak görseli için İngilizce, fotoğraf tarzı, metinsiz prompt.';

		$user = "Odak anahtar kelime: {$focus_keyword}\nDil: {$lang}\nHedef uzunluk: yaklaşık {$words} kelime.\n";
		if ( $ctx ) {
			$user .= "Site / marka bağlamı:\n{$ctx}\n";
		}
		$user .= 'Yerel SEO ve okuyucuya değer kat; abartılı iddia veya sahte istatistik kullanma.';

		$body = array(
			'model'           => $settings['text_model'] ?? 'gpt-4o-mini',
			'messages'        => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			'response_format' => array( 'type' => 'json_object' ),
			'temperature'     => 0.7,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = $raw['error']['message'] ?? __( 'OpenAI metin isteği başarısız.', 'ob-ai-seo-blog' );
			return new WP_Error( 'openai_text', $msg );
		}

		$content = $raw['choices'][0]['message']['content'] ?? '';
		$data    = json_decode( $content, true );
		if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['content_html'] ) ) {
			return new WP_Error( 'openai_parse', __( 'OpenAI yanıtı parse edilemedi.', 'ob-ai-seo-blog' ) );
		}

		return $data;
	}

	/**
	 * @return array{path:string,mime:string}|WP_Error
	 */
	public static function generate_image_file( string $prompt, array $settings ) {
		$api_key = OB_AI_Settings::get_api_key();
		if ( ! $api_key ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI API anahtarı tanımlı değil.', 'ob-ai-seo-blog' ) );
		}

		$model = $settings['image_model'] ?? 'gpt-image-1';

		$body = array(
			'model'   => $model,
			'prompt'  => $prompt,
			'size'    => '1536x1024',
			'quality' => 'medium',
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/images/generations',
			array(
				'timeout' => 180,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = $raw['error']['message'] ?? __( 'OpenAI görsel isteği başarısız.', 'ob-ai-seo-blog' );
			return new WP_Error( 'openai_image', $msg );
		}

		$b64 = $raw['data'][0]['b64_json'] ?? '';
		if ( ! $b64 ) {
			return new WP_Error( 'openai_image_empty', __( 'Görsel verisi alınamadı.', 'ob-ai-seo-blog' ) );
		}

		$binary = base64_decode( $b64, true );
		if ( false === $binary ) {
			return new WP_Error( 'openai_image_decode', __( 'Görsel decode edilemedi.', 'ob-ai-seo-blog' ) );
		}

		$tmp = wp_tempnam( 'ob-ai-image.png' );
		if ( ! $tmp ) {
			return new WP_Error( 'temp_file', __( 'Geçici dosya oluşturulamadı.', 'ob-ai-seo-blog' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $binary );

		return array(
			'path' => $tmp,
			'mime' => 'image/png',
		);
	}
}
