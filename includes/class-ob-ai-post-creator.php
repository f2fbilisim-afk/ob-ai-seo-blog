<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OB_AI_Post_Creator {

	/**
	 * @return int|WP_Error Post ID
	 */
	public static function create_from_queue_item( object $item ) {
		$settings = OB_AI_Settings::get();
		$keyword  = $item->focus_keyword;

		$article = OB_AI_OpenAI::generate_article( $keyword, $settings );
		if ( is_wp_error( $article ) ) {
			return $article;
		}

		$content_html = (string) $article['content_html'];
		$featured_id  = 0;

		if ( ! empty( $settings['generate_images'] ) ) {
			$cover_prompt = (string) ( $article['image_prompt'] ?? "Professional blog cover about {$keyword}, modern, no text" );
			$featured_id  = self::attach_image_from_prompt( $cover_prompt, $keyword . ' kapak', $settings );
		}

		$inline_prompts = array();
		if ( ! empty( $settings['generate_images'] ) && ! empty( $article['inline_image_prompts'] ) && is_array( $article['inline_image_prompts'] ) ) {
			$inline_prompts = array_slice( $article['inline_image_prompts'], 0, (int) $settings['inline_image_count'] );
		}

		foreach ( $inline_prompts as $idx => $prompt ) {
			$att_id = self::attach_image_from_prompt( (string) $prompt, $keyword . ' görsel ' . ( $idx + 1 ), $settings );
			if ( is_wp_error( $att_id ) || ! $att_id ) {
				continue;
			}
			$url = wp_get_attachment_url( $att_id );
			if ( $url ) {
				$alt = esc_attr( $keyword );
				$fig = '<figure class="ob-ai-inline-image"><img src="' . esc_url( $url ) . '" alt="' . $alt . '" loading="lazy" /></figure>';
				$content_html .= "\n\n" . $fig;
			}
		}

		$publish_gmt = $item->publish_at_gmt ? $item->publish_at_gmt : current_time( 'mysql', true );
		$publish_loc = get_date_from_gmt( $publish_gmt );
		$now_gmt     = current_time( 'mysql', true );
		$status      = ( strtotime( $publish_gmt ) > strtotime( $now_gmt ) ) ? 'future' : 'publish';

		$postarr = array(
			'post_title'   => sanitize_text_field( $article['title'] ),
			'post_name'    => sanitize_title( $article['slug'] ?? $article['title'] ),
			'post_content' => wp_kses_post( $content_html ),
			'post_excerpt' => sanitize_textarea_field( $article['excerpt'] ?? '' ),
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_date'    => $publish_loc,
			'post_date_gmt'=> $publish_gmt,
		);

		$cat = (int) ( $settings['default_category'] ?? 0 );
		if ( $cat > 0 ) {
			$postarr['post_category'] = array( $cat );
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $featured_id && ! is_wp_error( $featured_id ) ) {
			set_post_thumbnail( $post_id, $featured_id );
		}

		OB_AI_Rank_Math::apply(
			$post_id,
			$keyword,
			array(
				'meta_title'       => $article['meta_title'] ?? '',
				'meta_description' => $article['meta_description'] ?? '',
			)
		);

		update_post_meta( $post_id, '_ob_ai_focus_keyword', sanitize_text_field( $keyword ) );
		update_post_meta( $post_id, '_ob_ai_generated', 1 );

		return $post_id;
	}

	/**
	 * @return int|WP_Error Attachment ID
	 */
	private static function attach_image_from_prompt( string $prompt, string $title, array $settings ) {
		$file = OB_AI_OpenAI::generate_image_file( $prompt, $settings );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename = sanitize_file_name( sanitize_title( $title ) . '.png' );
		$filearr  = array(
			'name'     => $filename,
			'tmp_name' => $file['path'],
			'type'     => $file['mime'],
			'error'    => 0,
			'size'     => filesize( $file['path'] ),
		);

		$upload = wp_handle_sideload( $filearr, array( 'test_form' => false ) );
		if ( ! empty( $upload['error'] ) ) {
			@unlink( $file['path'] );
			return new WP_Error( 'upload_error', $upload['error'] );
		}

		@unlink( $file['path'] );

		$attachment = array(
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => $upload['type'],
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}

		$meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $meta );
		update_post_meta( $attach_id, '_wp_attachment_image_alt', $title );

		return $attach_id;
	}
}
