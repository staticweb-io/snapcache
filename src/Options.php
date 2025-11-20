<?php declare(strict_types=1);

namespace SnapCache;

class Options {
    final public static $object_cache_types = [ 'disabled', 'memcached' ];

    /**
     * Attempt to coerce a string into a recognized object cache type.
     * If no match is found, return 'disabled'.
     * This allows for admins to set options in the CLI or DB without
     * having to strictly match case and format.
     */
    public static function conformObjectCacheType( string $s ): string {
        if ( in_array( $s, self::$object_cache_types, true ) ) {
            return $s;
        }

        $s = strtolower( trim( $s ) );
        return in_array( $s, self::$object_cache_types, true ) ? $s : 'disabled';
    }

    public static function getObjectCacheType(): string {
        $u = get_option( 'snapcache_object_cache', 'disabled' );
        $v = self::conformObjectCacheType( $u );

        if ( $u !== $v ) {
            update_option( 'snapcache_object_cache', $v, false );
        }

        return $v;
    }

    /**
     * Returns true if memcached servers are set by wp-config.php.
     * If false, servers are set by the `snapcache_object_cache` option.
     */
    public static function isMemcachedServersInWpConfig(): bool {
        return defined( 'SNAPCACHE_MEMCACHED_SERVERS' );
    }
}
