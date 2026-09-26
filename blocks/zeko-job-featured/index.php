<?php
/**
 * Server-side render for Zeko Featured Jobs block.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zeko jobs featured block render.
 *
 * @param array $attributes Attributes.
 */
function zeko_jobs_featured_block_render( array $attributes ): string {
	$limit = isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 6;

	return do_shortcode( '[zeko_jobs_featured limit="' . esc_attr( $limit ) . '"]' );
}
