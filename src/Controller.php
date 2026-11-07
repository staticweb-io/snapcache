<?php

namespace SnapCache;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Controller {
    /**
     * Main controller
     *
     * @var Controller Instance.
     */
    protected static $plugin_instance;

    protected function __construct() {}

    /**
     * @return Controller Instance of self.
     */
    public static function getInstance(): Controller {
        if ( null === self::$plugin_instance ) {
            self::$plugin_instance = new self();
        }

        return self::$plugin_instance;
    }

    public static function init(): Controller {
        $bootstrap_file = SNAPCACHE_PATH . 'snapcache.php';

        register_activation_hook(
            $bootstrap_file,
            self::activate( ... ),
        );

        register_deactivation_hook(
            $bootstrap_file,
            self::deactivate( ... ),
        );

        add_action(
            'admin_init',
            self::installObjectCache( ... ),
            10,
            0,
        );

        add_action(
            'add_option_snapcache_object_cache',
            self::onObjectCacheSet( ... ),
            10,
            3,
        );

        add_action(
            'update_option_snapcache_object_cache',
            fn( $old_value, $new_value, string $option )
            => self::onObjectCacheSet( $option, $new_value ),
            10,
            3,
        );

        add_action(
            'add_option_snapcache_use_apcu',
            fn() => self::installObjectCache(),
            10,
            0,
        );

        add_action(
            'update_option_snapcache_use_apcu',
            fn() => self::installObjectCache(),
            10,
            0,
        );

        if ( is_admin() ) {
            Admin\Controller::addUIElements();
        }

        return self::getInstance();
    }

    public static function callInEachBlog(
        callable $callback,
    ): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $site_ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT blog_id FROM %s WHERE site_id = %d;',
                $wpdb->blogs,
                $wpdb->siteid
            )
        );

        foreach ( $site_ids as $site_id ) {
            switch_to_blog( $site_id );
            $callback();
        }

        restore_current_blog();
    }

    public static function activate(
        bool $network_wide = false,
    ): void {
        self::installObjectCache();

        if ( $network_wide ) {
            self::callInEachBlog(
                self::clearAllOptionsCache( ... ),
            );
        } else {
            self::clearAllOptionsCache();
        }
    }

    /**
     * Prevent a situation where alloptions is cached with
     * the plugin not in active_plugins, and when the plugin
     * is reactivated, the stale active_plugins remains cached.
     * This prevents the plugin being seen as active.
     * Mitigate by clearing alloptions whenever we add, change,
     * or remove object-cache.php.
     */
    public static function clearAllOptionsCache(): void {
        // We call both wp_cache_delete and $mc->delete because
        // they could be different caches in some cases, and it's
        // better to make sure all possible caches get cleared.
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 'alloptions', 'options' );
        }

        $smc = Memcached::getSnapCacheMemcached();

        if ( $smc instanceof \SnapCacheMemcached ) {
            $smc->delete( 'alloptions', 'options' );
        }
    }

    public static function deactivate(
        bool $network_wide = false,
    ): void {
        $obj_cache = get_dropins()['object-cache.php'] ?? null;
        if ( $obj_cache !== null && $obj_cache['TextDomain'] === 'snapcache' ) {
            FilesHelper::deleteFile(
                WP_CONTENT_DIR . '/object-cache.php',
            );
            if ( Options::getObjectCacheType() !== 'disabled' ) {
                update_option( 'snapcache_object_cache', 'disabled', false );
            }
        }

        if ( $network_wide ) {
            self::callInEachBlog(
                self::clearAllOptionsCache( ... ),
            );
        } else {
            self::clearAllOptionsCache();
        }
    }

    /**
     * Install our object cache if one of these is true:
     * - There is currently no object cache and
     *   the `snapcache_object_cache` option === "memcached"
     *   and we can get a connection to memcached.
     * - There is an existing SnapCache object-cache.php
     *   and its version or use of APCu does not match
     *   what we would install now.
     *
     * @param bool $force Install without checking any of these.
     * @param bool $apcu Install the APCu variant whatever this PHP has.
     */
    public static function installObjectCache(
        bool $force = false,
        bool $apcu = false,
    ): void {
        if ( $force ) {
            FilesHelper::copyFile(
                SNAPCACHE_PATH . 'src/drop-in/object-cache-memcached'
                . ( $apcu || self::shouldUseApcu() ? '-apcu' : '' ) . '.php',
                WP_CONTENT_DIR . '/object-cache.php',
            );
            if ( Options::getObjectCacheType() !== 'memcached' ) {
                update_option( 'snapcache_object_cache', 'memcached', false );
            }
            return;
        }

        $obj_cache = get_dropins()['object-cache.php'] ?? null;

        if ( $obj_cache === null ) {
            if ( Options::getObjectCacheType() === 'memcached'
            && Memcached::extensionAvailable() ) {
                $mc = Memcached::getMemcached();
                if ( $mc instanceof \Memcached && $mc->getVersion() !== false ) {
                    self::installObjectCache( true );
                }
            }
        } elseif ( $obj_cache['TextDomain'] === 'snapcache' ) {
            if ( $obj_cache['Version'] !== SNAPCACHE_VERSION
                || self::installedDropInHasApcu() !== self::shouldUseApcu() ) {
                self::installObjectCache( true );
            }
        }
    }

    private static function installedDropInHasApcu(): bool {
        $obj_cache = get_dropins()['object-cache.php'] ?? null;
        return $obj_cache !== null && str_contains( $obj_cache['Description'], 'APCu' );
    }

    /**
     * WP-CLI's PHP can lack extensions that the web server's PHP has,
     * so without APCu here, it keeps what was installed before.
     */
    private static function shouldUseApcu(): bool {
        return Options::useApcu()
            && ( Memcached::apcuAvailable()
                || ( defined( 'WP_CLI' ) && self::installedDropInHasApcu() ) );
    }

    /**
     * Try to update object-cache.php when the
     * snapcache_object_cache option is changed.
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
    public static function onObjectCacheSet( string $option, mixed $new_value ): void {
        if ( $new_value === 'disabled' ) {
            self::deactivate();
        } elseif ( $new_value === 'memcached' ) {
            self::installObjectCache();
        }
    }
}
