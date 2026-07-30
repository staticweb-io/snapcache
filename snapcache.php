<?php
/**
 * Plugin Name:       SnapCache
 * Plugin URI:        https://github.com/staticweb-io/snapcache
 * Description:       Memcached object cache
 * Version:           1.1.1
 * Author:            StaticWeb.io
 * Author URI:        https://staticweb.io
 * Text Domain:       snapcache
 * Requires at least: 6.5
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

define( 'SNAPCACHE_VERSION', '1.1.1' );
define( 'SNAPCACHE_PATH', plugin_dir_path( __FILE__ ) );

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

add_filter( 'plugin_action_links_snapcache/snapcache.php', 'snapcache_plugin_action_links' );


if ( defined( 'WP_CLI' ) ) {
    SnapCache\CLI\Base::init();
}
