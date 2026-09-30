<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OB_AI_Rank_Math {

	public static function apply( int $post_id, string $focus_keyword, array $seo ): void {
		$title = sanitize_text_field( $seo['meta_title'] ?? '' );
		$desc  = sanitize_text_field( $seo['meta_description'] ?? '' );

		if ( $title ) {
			update_post_meta( $post_id, 'rank_math_title', $title );
		}
		if ( $desc ) {
			update_post_meta( $post_id, 'rank_math_description', $desc );
		}

		update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $focus_keyword ) );

		if ( $title ) {
			update_post_meta( $post_id, 'rank_math_facebook_title', $title );
			update_post_meta( $post_id, 'rank_math_twitter_title', $title );
		}
		if ( $desc ) {
			update_post_meta( $post_id, 'rank_math_facebook_description', $desc );
			update_post_meta( $post_id, 'rank_math_twitter_description', $desc );
		}

		$thumb_url = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $thumb_url ) {
			update_post_meta( $post_id, 'rank_math_facebook_image', esc_url_raw( $thumb_url ) );
			update_post_meta( $post_id, 'rank_math_twitter_image', esc_url_raw( $thumb_url ) );
		}
	}
}
