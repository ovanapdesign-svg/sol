<?php
declare(strict_types=1);

namespace ConfigKit\Settings;

/**
 * Registers ConfigKit general settings via the WP Settings API.
 * Fields per ADMIN_SITEMAP.md §2.8.1.
 *
 * Frontend mode is omitted in Phase 3 (per spec "hidden in Phase 3,
 * Phase 4+"). Currency is fixed to NOK and unit fixed to mm in the
 * default value but editable via this form.
 */
final class GeneralSettings {

	public const OPTION_GROUP = 'configkit_general';
	public const PAGE_SLUG    = 'configkit-settings';

	/** @var array<string, array<string,mixed>> */
	private array $fields;

	public function __construct() {
		$this->fields = [
			'configkit_currency' => [
				'label'   => \__( 'Currency', 'configkit' ),
				'type'    => 'select',
				'choices' => [ 'NOK' => 'NOK', 'EUR' => 'EUR', 'SEK' => 'SEK', 'USD' => 'USD' ],
				'default' => 'NOK',
			],
			'configkit_measurement_unit' => [
				'label'   => \__( 'Measurement unit', 'configkit' ),
				'type'    => 'select',
				'choices' => [ 'mm' => 'mm', 'cm' => 'cm', 'm' => 'm' ],
				'default' => 'mm',
			],
			'configkit_price_display' => [
				'label'   => \__( 'Price display', 'configkit' ),
				'type'    => 'select',
				'choices' => [
					'incl_vat' => \__( 'Including VAT', 'configkit' ),
					'excl_vat' => \__( 'Excluding VAT', 'configkit' ),
				],
				'default' => 'incl_vat',
			],
			'configkit_lookup_match_default' => [
				'label'   => \__( 'Lookup match default', 'configkit' ),
				'type'    => 'select',
				'choices' => [
					'exact'    => 'exact',
					'round_up' => 'round_up',
					'nearest'  => 'nearest',
				],
				'default' => 'round_up',
			],
			'configkit_server_side_validation' => [
				'label'   => \__( 'Server-side validation', 'configkit' ),
				'type'    => 'checkbox',
				'default' => true,
			],
			'configkit_debug_mode' => [
				'label'   => \__( 'Debug mode', 'configkit' ),
				'type'    => 'checkbox',
				'default' => false,
			],
		];
	}

	public function register(): void {
		\add_settings_section(
			'configkit_general_section',
			\__( 'General settings', 'configkit' ),
			static function (): void {
				echo '<p>' . \esc_html__( 'Defaults applied across ConfigKit. These can be overridden per-template later.', 'configkit' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		foreach ( $this->fields as $name => $field ) {
			\register_setting(
				self::OPTION_GROUP,
				$name,
				[
					'type'              => $field['type'] === 'checkbox' ? 'boolean' : 'string',
					'default'           => $field['default'],
					'sanitize_callback' => fn( $value ) => $this->sanitize( $name, $value ),
				]
			);
			\add_settings_field(
				$name,
				$field['label'],
				fn() => $this->render_field( $name ),
				self::PAGE_SLUG,
				'configkit_general_section',
				[ 'label_for' => $name ]
			);
		}
	}

	public function render_field( string $name ): void {
		$field = $this->fields[ $name ] ?? null;
		if ( $field === null ) {
			return;
		}

		$value = \get_option( $name, $field['default'] );

		if ( $field['type'] === 'select' ) {
			echo '<select name="' . \esc_attr( $name ) . '" id="' . \esc_attr( $name ) . '">';
			foreach ( $field['choices'] as $choice => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					\esc_attr( (string) $choice ),
					\selected( (string) $value, (string) $choice, false ),
					\esc_html( (string) $label )
				);
			}
			echo '</select>';
			return;
		}

		if ( $field['type'] === 'checkbox' ) {
			printf(
				'<input type="checkbox" name="%1$s" id="%1$s" value="1"%2$s> ',
				\esc_attr( $name ),
				\checked( (bool) $value, true, false )
			);
		}
	}

	private function sanitize( string $name, mixed $value ): mixed {
		$field = $this->fields[ $name ] ?? null;
		if ( $field === null ) {
			return $value;
		}

		if ( $field['type'] === 'select' ) {
			$value = is_string( $value ) ? $value : '';
			return array_key_exists( $value, $field['choices'] ) ? $value : $field['default'];
		}

		if ( $field['type'] === 'checkbox' ) {
			return (bool) $value;
		}

		return $value;
	}
}
