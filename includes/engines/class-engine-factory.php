<?php
defined( 'ABSPATH' ) || exit;

class DQA_Engine_Factory {

	/**
	 * Create an engine instance from the current plugin settings.
	 *
	 * @param string $model_override Optional model override from chat UI.
	 * @return DQA_Engine_Core|WP_Error
	 */
	public static function make( string $model_override = '' ): DQA_Engine_Core|WP_Error {
		$provider = DQA_Settings::get( 'provider', 'anthropic' );
		$api_key  = DQA_Settings::get( 'api_key' );
		$model    = ! empty( $model_override ) ? $model_override : DQA_Settings::get( 'model' );
		$max_rows = (int) DQA_Settings::get( 'max_rows', 100 );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'no_api_key',
				sprintf(
					/* translators: %s: URL to the plugin settings page */
					__( 'API key is not configured. Please go to <a href="%s">Settings → Data Query Assistant</a>.', 'data-query-assistant' ),
					admin_url( 'options-general.php?page=data-query-assistant' )
				)
			);
		}

		// Fall back to provider default if model not set
		if ( empty( $model ) ) {
			$providers = DQA_Settings::get_providers();
			$model     = $providers[ $provider ]['default_model'] ?? '';
		}

		switch ( $provider ) {
			case 'anthropic':
				return new DQA_Engine_Anthropic( $api_key, $model, $max_rows );
			case 'openai':
				return new DQA_Engine_OpenAI( $api_key, $model, $max_rows );
			case 'google':
				return new DQA_Engine_Google( $api_key, $model, $max_rows );
			default:
				/* translators: %s: provider key name */
				return new WP_Error( 'unknown_provider', sprintf( __( 'Unknown provider: %s', 'data-query-assistant' ), $provider ) );
		}
	}

	/**
	 * Return provider label for display.
	 */
	public static function get_provider_label(): string {
		$provider  = DQA_Settings::get( 'provider', 'anthropic' );
		$providers = DQA_Settings::get_providers();
		return $providers[ $provider ]['label'] ?? $provider;
	}
}
