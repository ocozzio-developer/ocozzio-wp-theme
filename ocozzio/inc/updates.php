<?php
/**
 * Self-updates: plugin-update-checker (bundled in inc/plugin-update-checker/) reads this theme's update feed on GitHub
 * and WordPress installs new versions in the background, with or without a child theme active.
 * No plugin is needed on the site; the deploy tooling only installs this theme when it's missing.
 *
 * Channel, set in wp-config.php: define( 'THEME_PARENT_CHANNEL', 'beta' );
 *   'stable' (default): update.json, the releases promoted for client sites
 *   'beta': update-beta.json, every release (our dev site)
 *   'off': no updates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCOZZIO_UPDATE_FEED = 'https://raw.githubusercontent.com/ocozzio-developer/ocozzio-wp-theme/update-feed/';

function ocozzio_update_channel() {
	$channel = defined( 'THEME_PARENT_CHANNEL' ) ? THEME_PARENT_CHANNEL : 'stable';
	return in_array( $channel, array( 'stable', 'beta', 'off' ), true ) ? $channel : 'stable';
}

if ( 'off' !== ocozzio_update_channel() ) {
	require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
	YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		OCOZZIO_UPDATE_FEED . ( 'beta' === ocozzio_update_channel() ? 'update-beta.json' : 'update.json' ),
		get_template_directory(),
		'ocozzio'
	);
}

add_filter( 'auto_update_theme', 'ocozzio_auto_update', 10, 2 );
function ocozzio_auto_update( $update, $item ) {
	if ( isset( $item->theme ) && 'ocozzio' === $item->theme ) {
		return 'off' !== ocozzio_update_channel();
	}
	return $update;
}
