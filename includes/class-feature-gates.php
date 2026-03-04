<?php
defined( 'ABSPATH' ) || exit;

class DQA_Feature_Gates {

	const TIER_FREE = 'free';
	const TIER_PRO  = 'pro';

	/**
	 * Returns current feature tier mode.
	 */
	public static function current_tier(): string {
		return self::TIER_PRO === DQA_Settings::get( 'feature_tier', self::TIER_FREE )
			? self::TIER_PRO
			: self::TIER_FREE;
	}

	/**
	 * Checks whether a feature is enabled for the current tier.
	 */
	public static function is_enabled( string $feature ): bool {
		$pro_features = [
			'scheduled_reports',
			'smart_alerts',
			'saved_dashboards',
		];

		if ( in_array( $feature, $pro_features, true ) ) {
			return self::TIER_PRO === self::current_tier();
		}

		return true;
	}

	/**
	 * Sends AJAX error when feature is disabled.
	 */
	public static function assert_enabled( string $feature ): bool {
		if ( self::is_enabled( $feature ) ) {
			return true;
		}

		wp_send_json_error(
			[
				'message' => __( 'This feature is available in Pro mode.', 'data-query-assistant' ),
				'code'    => 'feature_locked',
			],
			403
		);
		return false;
	}
}
