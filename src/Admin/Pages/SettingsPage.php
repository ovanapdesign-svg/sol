<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Settings\GeneralSettings;

/**
 * Settings page (currently single tab: General). Sub-pages Modules and
 * Logs are added in later Phase 3 chunks.
 */
final class SettingsPage extends AbstractPage {

	public function __construct( private GeneralSettings $general ) {}

	public function slug(): string {
		return 'configkit-settings';
	}

	public function capability(): string {
		return 'configkit_manage_settings';
	}

	public function page_title(): string {
		return \__( 'ConfigKit Settings', 'configkit' );
	}

	public function menu_title(): string {
		return \__( 'Settings', 'configkit' );
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap( \__( 'ConfigKit Settings', 'configkit' ) );

		echo '<form action="options.php" method="post" class="configkit-settings-form">';
		\settings_fields( GeneralSettings::OPTION_GROUP );
		\do_settings_sections( $this->slug() );
		\submit_button();
		echo '</form>';

		$this->close_wrap();
	}
}
