<?php
declare(strict_types=1);

namespace ConfigKit\Admin;

/**
 * Renders a breadcrumb trail at the top of every ConfigKit admin
 * surface. Each segment is either a clickable link (intermediate) or
 * plain text (the current location, always last).
 *
 * Pure presentational helper — no state, no DB.
 */
final class Breadcrumb {

	/**
	 * Render a single-line breadcrumb. The last segment is always
	 * plain text (the current location). When that segment carries an
	 * `href`, it is preserved on the span as a `data-cf-href`
	 * attribute so the JS-side `subBreadcrumb()` helper can promote
	 * it back into a link when extending the trail (Modules → New
	 * module, Templates → Edit "X" → Step "Y", etc.). This keeps the
	 * server and client breadcrumbs in a single nav — no second line.
	 *
	 * @param list<array{label:string, href?:string|null}> $segments
	 */
	public static function render( array $segments ): string {
		if ( count( $segments ) === 0 ) {
			return '';
		}
		$parts = [];
		$last  = count( $segments ) - 1;
		foreach ( $segments as $i => $seg ) {
			$label = (string) ( $seg['label'] ?? '' );
			if ( $label === '' ) continue;
			$href  = isset( $seg['href'] ) && is_string( $seg['href'] ) && $seg['href'] !== '' ? $seg['href'] : null;
			if ( $i === $last ) {
				$attr = $href !== null ? ' data-cf-href="' . \esc_attr( $href ) . '"' : '';
				$parts[] = '<span class="configkit-breadcrumb__current" aria-current="page"' . $attr . '>'
					. \esc_html( $label ) . '</span>';
			} elseif ( $href === null ) {
				$parts[] = '<span class="configkit-breadcrumb__current">' . \esc_html( $label ) . '</span>';
			} else {
				$parts[] = '<a class="configkit-breadcrumb__link" href="' . \esc_url( $href ) . '">'
					. \esc_html( $label ) . '</a>';
			}
		}

		return '<nav class="configkit-breadcrumb" aria-label="Breadcrumb">'
			. implode( '<span class="configkit-breadcrumb__sep" aria-hidden="true">›</span>', $parts )
			. '</nav>';
	}

	/**
	 * Convenience: build the "ConfigKit › <Page>" prefix shared by
	 * every top-level ConfigKit admin page.
	 */
	public static function configkit_root_href(): string {
		return function_exists( 'admin_url' )
			? \admin_url( 'admin.php?page=configkit-dashboard' )
			: 'admin.php?page=configkit-dashboard';
	}

	public static function admin_page_href( string $slug ): string {
		return function_exists( 'admin_url' )
			? \admin_url( 'admin.php?page=' . $slug )
			: 'admin.php?page=' . $slug;
	}
}
