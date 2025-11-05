<?php

namespace SnapCache;

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
        $bootstrap_file = SNAPCACHE_PATH . 'plugin.php';

        register_activation_hook(
            $bootstrap_file,
            self::activate( ... ),
        );

        register_deactivation_hook(
            $bootstrap_file,
            self::deactivate( ... ),
        );

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
        FilesHelper::copyFile(
            SNAPCACHE_PATH . 'src/drop-in/object-cache.php',
            WP_CONTENT_DIR . '/object-cache.php',
        );

        if ( $network_wide ) {
            self::callInEachBlog(
                self::activateForSingleSite( ... ),
            );
        }

        self::activateForSingleSite();
    }

    public static function activateForSingleSite(): void {
    }

    public static function deactivate(
        bool $network_wide = false,
    ): void {
        $obj_cache = get_dropins()['object-cache.php'] ?? null;
        if ( $obj_cache !== null && $obj_cache['TextDomain'] === 'snapcache' ) {
            FilesHelper::deleteFile(
                WP_CONTENT_DIR . '/object-cache.php',
            );
        }

        if ( $network_wide ) {
            self::callInEachBlog(
                self::deactivateForSingleSite( ... ),
            );
        }

        self::deactivateForSingleSite();
    }

    public static function deactivateForSingleSite(): void {
    }
}
