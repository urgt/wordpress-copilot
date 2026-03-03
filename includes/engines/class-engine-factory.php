<?php
defined( 'ABSPATH' ) || exit;

class WPC_Engine_Factory {

	/**
	 * Create an engine instance from the current plugin settings.
	 *
	 * @param string $model_override Optional model override from chat UI.
	 * @return WPC_Engine_Core|WP_Error
	 */
	public static function make( string $model_override = '' ): WPC_Engine_Core|WP_Error {
		$provider = WPC_Settings::get( 'provider', 'anthropic' );
		$api_key  = WPC_Settings::get( 'api_key' );
		$model    = ! empty( $model_override ) ? $model_override : WPC_Settings::get( 'model' );
		$max_rows = (int) WPC_Settings::get( 'max_rows', 100 );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'no_api_key',
				sprintf(
					/* translators: %s: URL to the plugin settings page */
					__( 'API key is not configured. Please go to <a href="%s">Settings → WP Copilot</a>.', 'wordpress-copilot' ),
					admin_url( 'options-general.php?page=wordpress-copilot' )
				)
			);
		}

		// Fall back to provider default if model not set
		if ( empty( $model ) ) {
			$providers = WPC_Settings::get_providers();
			$model     = $providers[ $provider ]['default_model'] ?? '';
		}

		switch ( $provider ) {
			case 'anthropic':
				return new WPC_Engine_Anthropic( $api_key, $model, $max_rows );
			case 'openai':
				return new WPC_Engine_OpenAI( $api_key, $model, $max_rows );
			case 'google':
				return new WPC_Engine_Google( $api_key, $model, $max_rows );
			default:
				return new WP_Error( 'unknown_provider', "Unknown provider: {$provider}" );
		}
	}

	/**
	 * Return provider label for display.
	 */
	public static function get_provider_label(): string {
		$provider  = WPC_Settings::get( 'provider', 'anthropic' );
		$providers = WPC_Settings::get_providers();
		return $providers[ $provider ]['label'] ?? $provider;
	}
}
