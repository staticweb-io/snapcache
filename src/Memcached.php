<?php declare(strict_types=1);

namespace SnapCache;

class Memcached {
    /**
     * Returns true if the APCu extension is available.
     * apc.enable_cli isn't checked, because the object cache
     * is for the web server's PHP, which WP-CLI may be installing it for.
     */
    public static function apcuAvailable(): bool {
        return extension_loaded( 'apcu' )
            && filter_var( ini_get( 'apc.enabled' ), FILTER_VALIDATE_BOOLEAN );
    }

    /**
     * Returns true if the memcached extension is
     * available.
     */
    public static function extensionAvailable(): bool {
        return extension_loaded( 'memcached' );
    }

    /*
     * Returns the \Memcached instance used by the
     * object cache, or a new \Memcached instance if
     * the object cache is not loaded or is not managed
     * by this plugin.
     *
     * @param bool $required If true, throw an exception
     * if unable to find a \Memcached instance.
     */
    public static function getMemcached(
        $required = false,
    ): ?\Memcached {
        $smc = self::getSnapCacheMemcached( $required );

        if ( $smc instanceof \SnapCacheMemcached ) {
            return $smc->mc;
        }

        return null;
    }

    /*
     * Returns the \SnapCacheMemcached instance used by the
     * object cache, or a new \SnapCacheMemcached instance if
     * the object cache is not loaded or is not managed
     * by this plugin.
     *
     * @param bool $required If true, throw an exception
     * if unable to find a \SnapCacheMemcached instance.
     */
    public static function getSnapCacheMemcached(
        $required = false,
    ): ?\SnapCacheMemcached {
        global $wp_object_cache;

        if ( class_exists( 'SnapCacheMemcached' )
        && $wp_object_cache instanceof \SnapCacheMemcached ) {
            return $wp_object_cache;
        }

        require_once SNAPCACHE_PATH . 'src/drop-in/object-cache-memcached.php';
        if ( class_exists( 'SnapCacheMemcached' ) ) {
            return \SnapCacheMemcached::initAndBuild();
        }

        if ( $required ) {
            throw new SnapCacheException(
                'Memcached is not enabled or is not managed by this plugin.'
            );
        }

        return null;
    }
}
