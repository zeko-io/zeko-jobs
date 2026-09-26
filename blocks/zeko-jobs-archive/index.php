<?php
/**
 * Server-side render for Zeko Jobs Archive block.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zeko jobs archive block render.
 *
 * @param array $attributes Attributes.
 */
function zeko_jobs_archive_block_render( array $attributes ): string {
	$limit        = isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 10;
	$show_filters = ! empty( $attributes['showFilters'] );
	$show_search  = ! empty( $attributes['showSearch'] );

	$attrs = array(
		'limit' => max( 1, min( 100, $limit ) ),
	);

	if ( ! $show_filters ) {
		$attrs['hide_filters'] = '1';
	}
	if ( ! $show_search ) {
		$attrs['hide_search'] = '1';
	}

	return do_shortcode( '[zeko_jobs_archive ' . http_build_query( $attrs, '', ' ' ) . ']' );
}
