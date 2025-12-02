<?php declare(strict_types=1);

namespace SnapCache;

class Memcached {
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

        require_once SNAPCACHE_PATH . 'src/drop-in/object-cache.php';
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
