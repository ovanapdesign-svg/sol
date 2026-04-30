<?php
declare(strict_types=1);

namespace ConfigKit\Adapters;

/**
 * Production binding for `ProductSearchProvider`. Uses `wc_get_products()`
 * which works for stores with thousands of products without loading the
 * whole set into memory.
 *
 * Spec: UI_LABELS_MAPPING.md §4 (component picker), PRODUCT_BINDING_SPEC §21.
 */
final class WooProductSearchProvider implements ProductSearchProvider {

	public function search( string $query, int $limit, int $page ): array {
		$limit = max( 1, min( 50, $limit ) );
		$page  = max( 1, $page );

		if ( ! function_exists( 'wc_get_products' ) ) {
			return [ 'items' => [], 'total' => 0, 'page' => $page, 'per_page' => $limit ];
		}

		$args = [
			'limit'    => $limit,
			'paginate' => true,
			'page'     => $page,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'status'   => [ 'publish', 'private' ],
		];
		if ( $query !== '' ) {
			// `s` matches against title/excerpt/content. SKU is matched
			// separately; we union both result sets when the owner
			// types a code-like string.
			$args['s'] = $query;
		}

		$result = \wc_get_products( $args );
		$products = is_object( $result ) && isset( $result->products ) ? $result->products : ( is_array( $result ) ? $result : [] );
		$total    = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $products );

		// SKU search: if owner typed something that looks code-like and
		// we got few results, also try SKU lookup so SKU lookups don't
		// fall through. wc_get_products returns matching products only
		// when sku exactly equals — which is fine for picker UX.
		if ( $query !== '' && count( $products ) < $limit ) {
			$by_sku = \wc_get_products( [
				'limit'  => $limit,
				'sku'    => $query,
				'status' => [ 'publish', 'private' ],
			] );
			if ( is_array( $by_sku ) ) {
				$existing_ids = array_map( static fn( $p ) => (int) $p->get_id(), $products );
				foreach ( $by_sku as $extra ) {
					$id = (int) $extra->get_id();
					if ( ! in_array( $id, $existing_ids, true ) ) {
						$products[] = $extra;
						$total++;
					}
				}
			}
		}

		$items = array_values( array_filter( array_map( [ $this, 'shape' ], $products ) ) );
		return [
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $limit,
		];
	}

	public function find( int $woo_product_id ): ?array {
		if ( $woo_product_id <= 0 ) return null;
		if ( ! function_exists( 'wc_get_product' ) ) return null;
		$product = \wc_get_product( $woo_product_id );
		if ( ! is_object( $product ) ) return null;
		return $this->shape( $product );
	}

	/**
	 * @return array{id:int,name:string,sku:string,price:?float,thumbnail_url:?string,status:string}|null
	 */
	private function shape( mixed $product ): ?array {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) return null;
		$id = (int) $product->get_id();
		if ( $id <= 0 ) return null;

		$price = method_exists( $product, 'get_price' ) ? $product->get_price() : null;
		$thumb = null;
		if ( function_exists( 'wp_get_attachment_image_url' ) && method_exists( $product, 'get_image_id' ) ) {
			$image_id = (int) $product->get_image_id();
			if ( $image_id > 0 ) {
				$url = \wp_get_attachment_image_url( $image_id, 'thumbnail' );
				$thumb = is_string( $url ) && $url !== '' ? $url : null;
			}
		}

		return [
			'id'            => $id,
			'name'          => (string) ( method_exists( $product, 'get_name' ) ? $product->get_name() : '' ),
			'sku'           => (string) ( method_exists( $product, 'get_sku' ) ? $product->get_sku() : '' ),
			'price'         => is_numeric( $price ) ? (float) $price : null,
			'thumbnail_url' => $thumb,
			'status'        => (string) ( method_exists( $product, 'get_status' ) ? $product->get_status() : 'publish' ),
		];
	}
}
