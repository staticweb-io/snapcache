<?php
/**
 * Plugin Name:       SnapCache
 * Plugin URI:        https://github.com/staticweb-io/snapcache
 * Description:       Memcached object cache
 * Version:           0.1.0
 * Author:            StaticWeb.io
 * Author URI:        https://staticweb.io
 * Text Domain:       snapcache
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

// Only run code for admins and the CLI
// All functionality for the public site is loaded by the drop-in(s)
if ( ! defined( 'WP_CLI' ) && ! is_admin() ) {
    return;
}

define( 'SNAPCACHE_VERSION', '0.1.0' );
define( 'SNAPCACHE_PATH', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'SNAPCACHE_DEBUG' ) ) {
    $enabled = WP_DEBUG || ( defined( 'WP_CLI' ) && WP_CLI::get_config( 'debug' ) );
    define( 'SNAPCACHE_DEBUG', $enabled );
}

if ( file_exists( SNAPCACHE_PATH . 'vendor/autoload.php' ) ) {
    require_once SNAPCACHE_PATH . 'vendor/autoload.php';
}

if ( ! class_exists( \SnapCache\Controller::class )
    && file_exists( SNAPCACHE_PATH . 'src/SnapCacheException.php' ) ) {
    require_once SNAPCACHE_PATH . 'src/SnapCacheException.php';
    throw new SnapCache\SnapCacheException(
        "Looks like you're trying to activate SnapCache from source code" .
        ', without compiling it first.'
    );
}

SnapCache\Controller::init();

/**
 * Define Settings link for plugin
 *
 * @param string[] $links array of links
 * @return string[] modified array of links
 */
function snapcache_plugin_action_links( $links ) {
    $settings_link =
        '<a href="admin.php?page=snapcache">' .
        __( 'Settings', 'snapcache' ) .
        '</a>';
    array_unshift( $links, $settings_link );

    return $links;
}

// add_filter(
// 'plugin_action_links_' .
// plugin_basename( __FILE__ ),
// 'snapcache_plugin_action_links'
// );

/**
 * Prevent WP scripts from loading which aren't useful
 * on a statically exported site
 */
function snapcache_deregister_scripts(): void {
    wp_dequeue_script( 'wp-embed' );
    wp_deregister_script( 'wp-embed' );
    wp_dequeue_script( 'comment-reply' );
    wp_deregister_script( 'comment-reply' );
}

add_action( 'wp_footer', 'snapcache_deregister_scripts' );

// TODO: move into own plugin for WP cleanup, don't belong in core
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );

if ( defined( 'WP_CLI' ) ) {
    SnapCache\CLI\Base::init();
}
