<?php
declare(strict_types=1);

namespace ConfigKit\Rest;

/**
 * Aggregates ConfigKit REST controllers and registers their routes on
 * the `rest_api_init` hook.
 */
final class Router {

	/** @var list<AbstractController> */
	private array $controllers = [];

	public function add( AbstractController $controller ): void {
		$this->controllers[] = $controller;
	}

	public function init(): void {
		\add_action( 'rest_api_init', [ $this, 'register_all' ] );
	}

	public function register_all(): void {
		foreach ( $this->controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
