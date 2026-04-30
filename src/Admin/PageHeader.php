<?php
declare(strict_types=1);

namespace ConfigKit\Admin;

/**
 * Renders the H1 + subtitle + primary/secondary action bar at the
 * top of a list page, plus a muted intro box explaining the page.
 *
 * Pure presentational.
 */
final class PageHeader {

	/**
	 * @param array{
	 *   title:string,
	 *   subtitle?:string,
	 *   intro?:string,
	 *   intro_id?:string,
	 *   primary?:array{label:string,href:string,external?:bool},
	 *   secondary?:array{label:string,href:string}
	 * } $opts
	 */
	public static function render( array $opts ): string {
		$out  = '<div class="configkit-pageheader">';
		$out .= '<div class="configkit-pageheader__titles">';
		$out .= '<h1 class="configkit-pageheader__h1 wp-heading-inline">' . \esc_html( (string) $opts['title'] ) . '</h1>';
		if ( ! empty( $opts['subtitle'] ) ) {
			$out .= '<p class="configkit-pageheader__subtitle">' . \esc_html( (string) $opts['subtitle'] ) . '</p>';
		}
		$out .= '</div>';

		$out .= '<div class="configkit-pageheader__actions">';
		if ( ! empty( $opts['primary'] ) ) {
			$primary = $opts['primary'];
			$ext     = ! empty( $primary['external'] );
			$target  = $ext ? ' target="_blank" rel="noopener"' : '';
			$out .= '<a class="button button-primary configkit-pageheader__primary" href="' . \esc_url( (string) $primary['href'] ) . '"' . $target . '>'
				. \esc_html( (string) $primary['label'] )
				. ( $ext ? ' ↗' : '' )
				. '</a>';
		}
		if ( ! empty( $opts['secondary'] ) ) {
			$secondary = $opts['secondary'];
			$out .= '<a class="configkit-pageheader__secondary" href="' . \esc_url( (string) $secondary['href'] ) . '">'
				. \esc_html( (string) $secondary['label'] ) . '</a>';
		}
		$out .= '</div>';
		$out .= '</div>';

		// Bare hr keeps WP's heading-end behaviour (notices below).
		$out .= '<hr class="wp-header-end">';

		if ( ! empty( $opts['intro'] ) ) {
			$intro_id = isset( $opts['intro_id'] ) && $opts['intro_id'] !== ''
				? (string) $opts['intro_id']
				: 'configkit-intro';
			$out .= '<div class="configkit-intro" data-intro-id="' . \esc_attr( $intro_id ) . '">'
				. '<span class="configkit-intro__icon" aria-hidden="true">ⓘ</span>'
				. '<p class="configkit-intro__body">' . \esc_html( (string) $opts['intro'] ) . '</p>'
				. '<button type="button" class="configkit-intro__dismiss" aria-label="Hide this guidance">Got it, hide this</button>'
				. '</div>';
		}

		return $out;
	}

	public static function dashboard_href(): string {
		return Breadcrumb::admin_page_href( 'configkit-dashboard' );
	}
}
