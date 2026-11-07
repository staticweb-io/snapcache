<?php
/**
 * Copied to the WordPress root by the tests, to run cache operations in
 * PHP-FPM. Takes JSON such as {"ops":[["set","k","v","g"]]} and returns
 * the result of each op. WP-CLI runs the same code with `wp eval`.
 */

// phpcs:disable

if ( class_exists( 'Memcached' ) ) {
    /**
     * Runs a callback at a chosen point of an operation on one key, so that
     * tests can run another process's step exactly where a race would.
     */
    class SnapCacheTestMemcached extends Memcached {
        public string $hook_key = '';
        public array $hooks = [];

        private function hook( string $name, string $key ): void {
            if ( $key === $this->hook_key && isset( $this->hooks[ $name ] ) ) {
                $this->hooks[ $name ]();
            }
        }

        public function get( string $key, ?callable $cache_cb = null, int $get_flags = 0 ): mixed {
            $value = parent::get( $key, $cache_cb, $get_flags );
            $this->hook( 'after_get', $key );
            return $value;
        }

        public function set( string $key, mixed $value, int $expiration = 0 ): bool {
            $this->hook( 'before_set', $key );
            $result = parent::set( $key, $value, $expiration );
            $this->hook( 'after_set', $key );
            return $result;
        }
    }
}

/**
 * A cache like the global one, as another process would have, with hooks.
 */
function snapcache_test_new_cache( string $k = '', array $hooks = [] ): SnapCacheMemcached {
    $cache = SnapCacheMemcached::initAndBuild();
    $mc = new SnapCacheTestMemcached();
    $mc->addServers( SnapCacheMemcached::getServersFromConfig() );
    $mc->hook_key = $k === '' ? '' : snapcache_test_cache_key( $k, '' );
    $mc->hooks = $hooks;
    $cache->mc = $mc;
    $cache->get_seeds();
    return $cache;
}

function snapcache_test_cache_prop( string $name ): mixed {
    global $wp_object_cache;
    return ( new ReflectionProperty( $wp_object_cache, $name ) )->getValue( $wp_object_cache );
}

function snapcache_test_cache_key( string $key, string $group, bool $global = false ): string {
    global $wp_object_cache;
    $method = new ReflectionMethod( $wp_object_cache, 'cache_key' );
    return $method->invoke( $wp_object_cache, $key, $group, $global );
}

function snapcache_test_run_op( array $op ): mixed {
    global $wp_object_cache;
    $name = array_shift( $op );
    return match ( $name ) {
        // Whether this request reads from APCu
        'apcu_active' => snapcache_test_cache_prop( 'apcu_prefix' ) !== null,
        // The APCu entry of a key
        'apcu_entry' => apcu_fetch(
            snapcache_test_cache_prop( 'apcu_prefix' )
            . snapcache_test_cache_key( $op[0], $op[1] ?? '' )
        ),
        // Changes memcached without telling APCu
        'mc_delete' => $wp_object_cache->mc->delete( snapcache_test_cache_key( $op[0], $op[1] ?? '' ) ),
        'mc_delete_generation' => $wp_object_cache->mc->delete(
            snapcache_test_cache_key( 'apcu_generation', 'snapcache', true )
        ),
        // A cache whose memcached is unreachable, as if it went down
        'unreachable' => snapcache_test_unreachable( $op[0] ),
        // A reader that got the old value, then a writer that finishes before
        // the reader fills APCu
        'race_fill_after_write' => snapcache_test_new_cache(
            $op[0],
            [ 'after_get' => fn() => snapcache_test_new_cache()->set( $op[0], $op[1] ) ],
        )->get( $op[0] ),
        // Two writers, where the first to write to memcached
        // is the last to update APCu
        'race_two_writers' => snapcache_test_new_cache(
            $op[0],
            [ 'after_set' => fn() => snapcache_test_new_cache()->set( $op[0], $op[2] ) ],
        )->set( $op[0], $op[1] ),
        // A writer without APCu, and an FPM request that reads and fills APCu
        // just before the write reaches memcached
        'race_fill_during_write' => snapcache_test_new_cache(
            $op[0],
            [
                'before_set' => fn() => file_get_contents(
                    $op[2],
                    false,
                    stream_context_create(
                        [
                            'http' => [
                                'content' => json_encode( [ 'ops' => [ [ 'get_uncached', $op[0] ] ] ] ),
                                'method' => 'POST',
                            ],
                        ]
                    ),
                ),
            ],
        )->set( $op[0], $op[1] ),
        // Without the local cache, so that reads go to APCu or memcached
        'get_uncached' => [ wp_cache_flush_runtime(), wp_cache_get( $op[0], $op[1] ?? '' ) ][1],
        'eval' => eval( $op[0] ),
        default => ( 'wp_cache_' . $name )( ...$op ),
    };
}

function snapcache_test_unreachable( string $id ): array {
    $cache = new SnapCacheMemcached(
        $id,
        fn() => [ [ '127.0.0.1', 1 ] ],
        '1',
    );
    $cache->get_seeds();
    return [
        'set' => $cache->set( 'k', 'v' ),
        'get' => $cache->get( 'k' ),
        'delete' => $cache->delete( 'k' ),
        'flush' => $cache->flush(),
    ];
}

if ( ! defined( 'WP_CLI' ) ) {
    if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
        $_SERVER['HTTP_HOST'] = 'localhost';
    }
    // The plugin's classes only load for admin requests and WP-CLI
    if ( getenv( 'SNAPCACHE_TEST_ADMIN' ) ) {
        define( 'WP_ADMIN', true );
    }
    require_once __DIR__ . '/wp-load.php';

    // For scenarios, which report failures this way
    if ( ! class_exists( 'WP_CLI' ) ) {
        class WP_CLI {
            public static function error( string $message ): void {
                throw new RuntimeException( $message );
            }
        }
    }

    $input = json_decode(
        PHP_SAPI === 'cli' ? $argv[1] : file_get_contents( 'php://input' ),
        true
    );
    header( 'Content-Type: application/json' );
    try {
        echo json_encode( array_map( snapcache_test_run_op( ... ), $input['ops'] ) );
    } catch ( Throwable $e ) {
        echo json_encode( [ 'error' => (string) $e ] );
    }
}
