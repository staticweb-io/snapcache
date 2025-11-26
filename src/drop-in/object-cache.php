<?php
/**
 * Plugin Name:       SnapCache Object Cache
 * Plugin URI:        https://github.com/staticweb-io/snapcache
 * Description:       Object caching for WordPress.
 * Version:           0.2.0
 * Author:            StaticWeb.io
 * Author URI:        https://github.com/staticweb-io/snapcache
 * Text Domain:       snapcache
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

// Allow discouraged functions so we can use error_log here.
// phpcs:disable Squiz.PHP.DiscouragedFunctions
// phpcs:disable WordPress.PHP.DevelopmentFunctions

// phpcs:disable Universal.Files.SeparateFunctionsFromOO

declare(strict_types=1);

if ( ! class_exists( 'Memcached' ) ) {
    wp_using_ext_object_cache( false );
} else {
    class SnapCacheMemcached {
        public Memcached $mc;

        private string $cache_key_salt_hash = '';
        // Array of group_name => true
        private array $global_groups;
        // Temporary cache that vanishes at
        // the end of script execution.
        private array $local_cache;
        // Marker object used to represent missing cache items
        private readonly object $local_missing_marker;
        // Array of group_name => array of keys => values
        private array $non_persistent_groups;
        // Array of pre-seeded keys
        private ?array $preseed_keys = null;

        public function __construct(
            string $persistent_id,
            callable $get_servers,
            /**
             * Prefix used for cache keys in non-global groups
             */
            private readonly string $non_global_prefix,
            string $cache_key_salt = '',
            /**
             * Prefix used for cache keys in global groups
             */
            private readonly string $global_prefix = 'global',
        ) {
            if ( $cache_key_salt !== '' ) {
                $this->cache_key_salt_hash = md5( $cache_key_salt );
            }

            $this->global_groups = [];
            $this->local_cache = [];
            $this->local_missing_marker = new stdClass();
            $this->non_persistent_groups = [];

            // https://www.php.net/manual/en/memcached.construct.php
            $mc = new Memcached( $persistent_id );

            // We can only enable binary protocol for new connections
            if ( $mc->isPristine() ) {
                $result = $mc->setOptions(
                    [
                        Memcached::OPT_CONNECT_TIMEOUT => 500,
                        Memcached::OPT_RETRY_TIMEOUT => 0,
                    ]
                );

                if ( $result === false ) {
                    error_log( 'Failed to set connection options for Memcached' );
                }

                if ( SNAPCACHE_MEMCACHED_USE_BINARY === true ) {
                    $result = $mc->setOptions(
                        [
                            Memcached::OPT_BINARY_PROTOCOL => true,
                            // Binary protocol is very slow without this
                            Memcached::OPT_TCP_NODELAY => true,
                        ]
                    );

                    if ( $result === false ) {
                        error_log( 'Failed to enable binary protocol for Memcached' );
                    }
                }
            }
            $admin = is_admin();
            $server_list = $mc->getServerList();
            // Since the Memcached instance persists across
            // requests, we must take care not to add servers
            // that are already in the list.
            if ( $admin || empty( $server_list ) ) {
                try {
                    $servers = $get_servers();
                } catch ( Exception $e ) {
                    error_log( 'Invalid memcached server format: ' . $e->getMessage() );
                    // If we can't set servers, we just return with no
                    // server list. This class will still work as an
                    // in-memory object cache. The admin UI logic can
                    // detect an invalid config and prompt the admin to
                    // fix it.
                }

                if ( ! is_array( $servers ) ) {
                    error_log( 'Invalid memcached server format' );
                } elseif ( count( $servers ) !== count( $server_list ) ) {
                    if ( ! ( $mc->resetServerList() && $mc->addServers( $servers ) ) ) {
                        error_log( 'Memcached addServers failed' );
                    }
                } else {
                    $comparator = function ( array $a, array $b ): int {
                        if ( array_is_list( $a ) ) {
                            return $a[0] === $b['host'] && $a[1] === $b['port'] ? 0 : -1;
                        }
                        return $b[0] === $a['host'] && $b[1] === $a['port'] ? 0 : 1;
                    };
                    if ( ( array_udiff( $servers, $server_list, $comparator ) !== []
                        || array_udiff( $server_list, $servers, $comparator ) !== [] )
                        ) {
                        if ( $mc->resetServerList() && $mc->addServers( $servers ) ) {
                            // If servers were changed, then some data is probably
                            // out of sync.
                            if ( ! empty( $server_list ) ) {
                                $mc->flush();
                            }
                        } else {
                            error_log( 'Memcached addServers failed' );
                        }
                    }
                }
            }

            $this->mc = $mc;
        }

        /**
         * Parses an array or string format server data into
         * [ $server, $port, $weight ].
         * Throws an \Exception if the data cannot be parsed.
         */
        public static function parseServerToArray( mixed $data ): array {
            if ( is_array( $data ) ) {
                // Ensure array has at least server, port defaults to 11211, weight defaults to 0
                $server = $data[0] ?? null;
                if ( $server === null ) {
                    throw new Exception( 'Invalid server data: missing server' );
                }
                $port = isset( $data[1] ) && $data[1] !== '' ? (int) $data[1] : 11211;
                $weight = isset( $data[2] ) && $data[2] !== '' ? (int) $data[2] : 0;
                return [ $server, $port, $weight ];
            }
            if ( is_string( $data ) ) {
                // We intentionally leave values after extra spaces
                // uninterpreted and reserved for future use.
                $parts = explode( ' ', $data );
                $weight = isset( $parts[1] ) && $parts[1] !== '' ? (int) $parts[1] : 0;
                if ( ! isset( $parts[0] ) ) {
                    throw new Exception( 'Invalid server data' );
                }
                $parts = explode( ':', $parts[0] );
                if ( ! isset( $parts[0] ) ) {
                    throw new Exception( 'Invalid server data' );
                }
                $server = $parts[0];
                $port = isset( $parts[1] ) && $parts[1] !== '' ?
                    (int) $parts[1] : 11211;
                return [ $server, $port, $weight ];
            }
            throw new Exception(
                'Invalid server data. Expected array or string. '
            );
        }

        /**
         * Returns configured servers.
         * Attempts to process them into the format
         * [ [ $server, $port, $weight ] ]
         * Throws an \Exception if servers cannot be processed.
         */
        public static function getServersFromConfig(): array {
            if ( defined( 'SNAPCACHE_MEMCACHED_SERVERS' ) ) {
                return array_map( self::parseServerToArray( ... ), SNAPCACHE_MEMCACHED_SERVERS );
            }

            // If SNAPCACHE_MEMCACHED_SERVERS is not set,
            // try to look it up an option from the
            // DB. We can do this because any competent
            // host will keep persistent connections,
            // so we won't do this query for most requests.
            // WP-CLI doesn't keep connections, but that's ok
            // because this is a very cheap query to do.
            global $wpdb;
            // We can't use get_option because it tries
            // to call us. We have to do a direct query.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $result = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                    'snapcache_memcached_servers'
                )
            );
            $raw = $result ? maybe_unserialize( $result ) : '';
            // Since the admin may type servers in a textarea, we want
            // to ignore blank lines and extra whitespace.
            $lines = array_map( trim( ... ), explode( "\n", $raw ) );
            $non_blank_lines = array_filter( $lines, fn( $line ): bool => $line !== '' );
            return array_map( self::parseServerToArray( ... ), $non_blank_lines );
        }

        /**
         * Sets global state if needed and returns
         * a new instance of self.
         */
        public static function initAndBuild(): self {
            if ( ! defined( 'WP_CACHE_KEY_SALT' ) ) {
                define( 'WP_CACHE_KEY_SALT', '' );
            }

            if ( ! defined( 'SNAPCACHE_MEMCACHED_PERSISTENT_ID' ) ) {
                define( 'SNAPCACHE_MEMCACHED_PERSISTENT_ID', 'snapcache' );
            }

            if ( ! defined( 'SNAPCACHE_PRESEEDS' ) ) {
                define(
                    'SNAPCACHE_PRESEEDS',
                    [
                        [ 'is_blog_installed', 'default' ],
                        [ 'alloptions', 'options' ],
                        [ 'notoptions', 'options' ],
                        [ get_current_blog_id() . ':notoptions', 'site-options', true ],
                        [ 'doing_cron', 'transient' ],
                        [ 'wp_core_block_css_files', 'transient' ],
                        [ 'wp_styles_for_blocks', 'transient' ],
                    ]
                );
            }

            if ( ! defined( 'SNAPCACHE_MEMCACHED_USE_BINARY' ) ) {
                // The binary protocol has severe perfomance issues
                // See https://github.com/memcached/memcached/issues/775
                define( 'SNAPCACHE_MEMCACHED_USE_BINARY', false );
            }

            return new SnapCacheMemcached(
                SNAPCACHE_MEMCACHED_PERSISTENT_ID,
                self::getServersFromConfig( ... ),
                (string) get_current_blog_id(),
                WP_CACHE_KEY_SALT,
            );
        }

        private function can_add(): bool
        {
            return ! wp_suspend_cache_addition();
        }

        private function cache_key(
            int|string $key,
            string $group,
            bool $force_global = false,
        ): string {
            if ( $key === '' ) {
                throw new Exception( 'Cache key cannot be empty' );
            }

            // For compatibility with WordPress
            if ( $group === '' ) {
                $group = 'default';
            }

            $prefix = ( $force_global || isset( $this->global_groups[ $group ] ) )
            ? $this->global_prefix
            : $this->non_global_prefix;

            $key = $prefix . $group . ':' . $key . $this->cache_key_salt_hash;

            // Unfortunately WordPress uses a lot of keys with spaces,
            // and the memcached text protocol doesn't allow that.
            if ( ! SNAPCACHE_MEMCACHED_USE_BINARY && str_contains( $key, ' ' ) ) {
                $key = str_replace( ' ', '_', $key );
            }

            // Truncate long keys and add a hash.
            // Memcached only allows 250 bytes for keys.
            if ( strlen( $key ) >= 250 ) {
                // Preserve as much of the key as we can.
                // Split the key without breaking up any
                // multi-byte characters.
                $key_start = mb_substr( $key, 0, 218 );
                $sl = strlen( $key_start );
                while ( $sl > 218 ) {
                    $key_start = mb_substr(
                        $key_start,
                        0,
                        218 - ( $sl - 218 ),
                    );

                    $sl = strlen( $key_start );
                }

                // Append the key's hash to the part of the
                // key that we were able to keep.
                $key = $key_start . md5( $key );
            }

            return $key;
        }

        /**
         * Convert WordPress $expire args to Memcache
         * expiration.
         *
         * WordPress $expire args to cache functions are
         * always the number of seconds that the item expires in,
         * with 0 meaning no expiration.
         *
         * Memcached expiration behaves the same for 0 and values
         * under 60*60*24*30 (number of seconds in 30 days). But if
         * the value is larger than that, it is treated as a
         * Unix timestamp.
         *
         * If $expire is under this limit, we return it unchanged.
         * Otherwise, we convert it to a Unix timestamp.
         *
         * See https://www.php.net/manual/en/memcached.expiration.php
         */
        public static function to_memcache_expiration(
            int $expire,
        ): int {
            // Number of seconds in 30 days
            if ( $expire <= 2592000 ) {
                return $expire;
            }
            return $expire + time();
        }

        /**
         * Clone $data if it is a type that is passed
         * by reference.
         */
        public static function maybe_clone(
            mixed $data,
        ): mixed {
            if ( is_object( $data ) ) {
                return clone $data;
            }
            return $data;
        }

        /**
         * Adds data to the cache only if the key is not
         * already present in the cache.
         *
         * $expire is ignored for non-persistent groups, because
         * they vanish at the end of script execution.
         */
        public function add(
            int|string $key,
            mixed $data,
            string $group = '',
            int $expire = 0,
        ): bool {
            if ( ! $this->can_add() ) {
                return false;
            }

            $k = $this->cache_key( $key, $group );

            if ( isset( $this->non_persistent_groups[ $group ] )
            && ! array_key_exists( $k, $this->non_persistent_groups[ $group ] ) ) {
                $data = self::maybe_clone( $data );
                $this->non_persistent_groups[ $group ][ $k ] = $data;
                return true;
            }

            if ( $this->preseed_keys ) {
                $this->fetch_all();
            }

            $expire = self::to_memcache_expiration( $expire );
            if ( $this->mc->add( $k, $data, $expire ) ) {
                $this->local_cache[ $k ] = self::maybe_clone( $data );
                return true;
            }
            // We don't know the state of the memcached item
            unset( $this->local_cache[ $k ] );
            return false;
        }

        /**
         * Adds items to the cache only if the key is not
         * already present in the cache.
         *
         * $expire is ignored for non-persistent groups, because
         * they vanish at the end of script execution.
         */
        public function add_multiple(
            array $data,
            string $group = '',
            int $expire = 0,
        ): array {
            if ( ! $this->can_add() || $data === [] ) {
                return [];
            }

            if ( isset( $this->non_persistent_groups[ $group ] ) ) {
                $arr = [];
                foreach ( array_keys( $data ) as $key ) {
                    $k = $this->cache_key( $key, $group );
                    if ( array_key_exists( $k, $this->non_persistent_groups[ $group ] ) ) {
                        $arr[ $key ] = false;
                    } else {
                        $data = self::maybe_clone( $data );
                        $this->non_persistent_groups[ $group ][ $k ] = $data;
                        $arr[ $key ] = true;
                    }
                }
                return $arr;
            }

            if ( $this->preseed_keys ) {
                $this->fetch_all();
            }

            $expire = self::to_memcache_expiration( $expire );
            $arr = [];
            // Memcached doesn't support addMulti, so we
            // iterate over many add() calls.
            foreach ( $data as $key => $v ) {
                $k = $this->cache_key( $key, $group );
                if ( $this->mc->add( $k, $v, $expire ) ) {
                    $this->local_cache[ $k ] = self::maybe_clone( $v );
                    $arr[ $key ] = true;
                } else {
                    // We don't know the state of the memcached item
                    unset( $this->local_cache[ $k ] );
                    $arr[ $key ] = false;
                }
            }

            return $arr;
        }

        public function add_global_groups(
            array $groups,
        ): void {
            $this->global_groups = array_merge(
                $this->global_groups,
                array_fill_keys( $groups, true ),
            );
        }

        public function add_non_persistent_groups(
            array $groups,
        ): void {
            $this->non_persistent_groups = array_merge(
                $this->non_persistent_groups,
                array_fill_keys( $groups, [] ),
            );
        }

        /**
         * Decrement the value of a numeric cache item.
         * Returns false if the cache item does not exist
         * or is not numeric.
         */
        public function decr(
            int|string $key,
            int $offset = 1,
            string $group = '',
        ): int|false {
            return $this->incr( $key, -$offset, $group );
        }

        /**
         * Deletes an item from the cache.
         */
        public function delete(
            int|string $key,
            string $group = '',
        ): bool {
            $k = $this->cache_key( $key, $group );

            if ( isset( $this->non_persistent_groups[ $group ] ) ) {
                unset( $this->non_persistent_groups[ $group ][ $k ] );
                return true;
            }

            if ( $this->preseed_keys ) {
                $this->fetch_all();
            }

            if ( $this->mc->delete( $k ) ) {
                $this->local_cache[ $k ] = $this->local_missing_marker;
                return true;
            }
            return false;
        }

        /**
         * Deletes multiple items from the cache.
         *
         * Returns an array of bools grouped by key
         * indicating successful deletion.
         *
         * @param int[]|string[] $keys
         */
        public function delete_multiple(
            array $keys,
            string $group = '',
        ): array {
            if ( $keys === [] ) {
                return [];
            }

            $ks = array_map(
                fn( int|string $k ): string => $this->cache_key( $k, $group ),
                $keys,
            );

            if ( isset( $this->non_persistent_groups[ $group ] ) ) {
                foreach ( $ks as $k ) {
                    unset( $this->non_persistent_groups[ $group ][ $k ] );
                }
                return array_fill_keys( $ks, true );
            }

            if ( $this->preseed_keys ) {
                $this->fetch_all();
            }

            $results = $this->mc->deleteMulti( $ks );

            foreach ( $results as $k => $v ) {
                if ( $v === true ) {
                    $this->local_cache[ $k ] = $this->local_missing_marker;
                } else {
                    // We aren't sure of the state of the item
                    unset( $this->local_cache[ $k ] );
                    // Turn Memcached::RES_* constants into false
                    $results[ $k ] = false;
                }
            }

            return $results;
        }

        /**
         * Fetch all outstanding values from a
         * Memcached->getDelayed() request.
         * Results are loaded into the local cache.
         */
        private function fetch_all(): void {
            $results = $this->mc->fetchAll();
            if ( $results === false ) {
                return;
            }

            $preseed_map = array_fill_keys(
                $this->preseed_keys,
                true,
            );
            $this->preseed_keys = null;

            foreach ( $results as $result ) {
                $k = $result ['key'];
                $this->local_cache[ $k ] = $result['value'];
                unset( $preseed_map[ $k ] );
            }

            // We want to seed misses as well because the extra
            // processing to track misses is cheap compared to
            // the cost of making an extra memcached request.
            foreach ( array_keys( $preseed_map ) as $miss ) {
                $this->local_cache[ $miss ] = $this->local_missing_marker;
            }
        }

        /**
         * Removes all cache items.
         */
        public function flush(): bool {
            return $this->mc->flush() && $this->flush_runtime();
        }

        /**
         * Removes all cache items.
         */
        public function flush_runtime(): bool {
            $this->local_cache = [];
            $this->non_persistent_groups =
                array_fill_keys(
                    array_keys( $this->non_persistent_groups ),
                    []
                );
            return true;
        }

        /**
         * Returns data from the cache, if present.
         *
         * Returns false if the key is not found. Note that
         * false could also be a value that was found in the
         * cache. In order to distinguish between these
         * circumstances, the $found parameter is set to true
         * if the key was found, false otherwise.
         *
         * $force determines whether we can serve the value
         * from our local cache or if we have to retrieve
         * the value from memcached.
         */
        public function get(
            int|string $key,
            string $group = '',
            bool $force = false,
            ?bool &$found = null
        ): mixed {
            $k = $this->cache_key( $key, $group );

            if ( isset( $this->non_persistent_groups[ $group ] ) ) {
                if ( array_key_exists( $k, $this->non_persistent_groups[ $group ] ) ) {
                    $found = true;
                    return self::maybe_clone( $this->non_persistent_groups[ $group ][ $k ] );
                }
                $found = false;
                return false;
            }

            if ( $this->preseed_keys ) {
                $this->fetch_all();
            }

            if ( ! $force && array_key_exists( $k, $this->local_cache ) ) {
                $v = $this->local_cache[ $k ];
                if ( $v === $this->local_missing_marker ) {
                    $found = false;
                    return false;
                }
                $found = true;
                return self::maybe_clone( $v );
            }

            $data = $this->mc->get( $k );
            $found = $data !== false || $this->mc->getResultCode() === Memcached::RES_SUCCESS;

            $this->local_cache[ $k ] = $found
            ? self::maybe_clone( $data )
            : $this->local_missing_marker;

            return $data;
        }

        /**
         * Returns an array of items from the cache, if present.
         *
         * Array of return values, grouped by key. Each value is
         * either the cache contents on success, or false on
         * failure. If failure must be distinguished from a
         * false value, use get() with the &$found arg.
         *
         * $force determines whether we can serve values
         * from our local cache or if we have to retrieve
         * the values from memcached. If false, the response
         * data may include a mixture of data from the local
         * cache and from memcached.
         *
         * @param int[]|string[] $keys
         */
        public function get_multiple(
            array $keys,
            string $group = '',
            bool $force = false,
        ): mixed {
            if ( $keys === [] ) {
                return [];
            }

            $ks = array_map(
                fn( int|string $k ): string => $this->cache_key( $k, $group ),
                $keys,
            );

            if ( isset( $this->non_persistent_groups[ $group ] ) ) {
                $arr = [];
                foreach ( $ks as $k ) {
                    if ( array_key_exists( $k, $this->non_persistent_groups[ $group ] ) ) {
                        $arr[ $k ] = self::maybe_clone(
                            $this->non_persistent_groups[ $group ][ $k ],
                        );
                    } else {
                        $arr[ $k ] = false;
                    }
                }
                return $arr;
            }

            if ( $this->preseed_keys ) {
                $this->fetch_all();
            }

            if ( ! $force ) {
                // Look up as many locally cached keys as we can
                $local = [];
                foreach ( $ks as $k ) {
                    if ( array_key_exists( $k, $this->local_cache ) ) {
                        $v = $this->local_cache[ $k ];
                        $local[ $k ] = $v === $this->local_missing_marker
                        ? false
                        : self::maybe_clone( $v );
                    }
                }
            } else {
                $local = null;
            }

            // Don't hit memcached for items we found locally
            if ( $local !== null && $local !== [] ) {
                $ks_to_keys = array_combine( $ks, $keys );
                $arr = [];
                foreach ( $ks_to_keys as $k => $key ) {
                    if ( array_key_exists( $k, $local ) ) {
                        $arr[ $key ] = $local[ $k ];
                    }
                }
                $ks = array_diff( $ks, array_keys( $local ) );

                // All keys were found locally
                if ( $ks === [] ) {
                    return $arr;
                }

                $local = $arr;
            }

            $result = $this->mc->getMulti( $ks );
            if ( $result === false ) {
                if ( $local === null || $local === [] ) {
                    return array_fill_keys( $keys, false );
                }
                return array_merge(
                    array_fill_keys( $keys, false ),
                    $local,
                );
            }

            if ( ! isset( $ks_to_keys ) ) {
                $ks_to_keys = array_combine( $ks, $keys );
            }

            // These do essentially the same thing if $local is
            // empty, but the then branch can skip the lookups.
            if ( $local === null || $local === [] ) {
                $arr = [];
                foreach ( $ks_to_keys as $k => $key ) {
                    if ( isset( $result[ $k ] ) ) {
                        $v = $result[ $k ];
                        $this->local_cache[ $k ] = self::maybe_clone( $v );
                        $arr[ $key ] = $v;
                    } else {
                        unset( $this->local_cache[ $k ] );
                        $arr[ $key ] = false;
                    }
                }
                return $arr;
            }
            $arr = [];
            foreach ( $ks_to_keys as $k => $key ) {
                if ( isset( $result[ $k ] ) ) {
                    $v = $result[ $k ];
                    $this->local_cache[ $k ] = self::maybe_clone( $v );
                    $arr[ $key ] = $v;
                } elseif ( isset( $local[ $key ] ) ) {
                    $arr[ $key ] = $local[ $key ];
                } else {
                    unset( $this->local_cache[ $k ] );
                    $arr[ $key ] = false;
                }
            }
            return $arr;
        }

        /**
         * Make a delayed request for values that we want
         * to seed before they are needed.
         */
        public function get_seeds(): void {
            if ( empty( SNAPCACHE_PRESEEDS ) ) {
                return;
            }

            $preseed_keys = array_map(
                fn( array $preseed ): string => $this->cache_key(
                    $preseed[0],
                    $preseed[1],
                    $preseed[2] ?? false,
                ),
                SNAPCACHE_PRESEEDS,
            );

            if ( $this->mc->getDelayed( $preseed_keys ) ) {
                $this->preseed_keys = $preseed_keys;
            }
        }

        /**
         * Increment the value of a numeric cache item.
         * Returns false if the cache item does not exist
         * or is not numeric.
         */
        public function incr(
            int|string $key,
            int $offset = 1,
            string $group = '',
        ): int|false {
            $k = $this->cache_key( $key, $group );

            if ( isset( $this->non_persistent_groups[ $group ] ) ) {
                $v = $this->non_persistent_groups[ $group ][ $k ] ?? null;
                if ( is_numeric( $v ) ) {
                    $v += $offset;
                    $this->non_persistent_groups[ $group ][ $k ] = $v;
                    return $v;
                }
                return false;
            }

            $result = $this->mc->increment( $k, $offset );

            if ( $result === false ) {
                // We don't know the state of the memcached item
                unset( $this->local_cache[ $k ] );
                return false;
            }
            $this->local_cache[ $k ] = $result;
            return $result;
        }

        /**
         * Replaces data in the cache only if the key is
         * already present in the cache.
         *
         * $expire is ignored for non-persistent groups, because
         * they vanish at the end of script execution.
         */
        public function replace(
            int|string $key,
            mixed $data,
            string $group = '',
            int $expire = 0,
        ): bool {
            if ( ! $this->can_add() ) {
                return false;
            }

            $k = $this->cache_key( $key, $group );

            if ( isset( $this->non_persistent_groups[ $group ] )
            && array_key_exists( $k, $this->non_persistent_groups[ $group ] ) ) {
                $data = self::maybe_clone( $data );
                $this->non_persistent_groups[ $group ][ $k ] = $data;
                return true;
            }

            if ( $this->preseed_keys ) {
                $this->fetch_all();
            }

            $expire = self::to_memcache_expiration( $expire );
            $result = $this->mc->replace( $k, $data, $expire );

            if ( $result === false ) {
                // We don't know the state of the memcached item
                unset( $this->local_cache[ $k ] );
                return false;
            }
            $this->local_cache[ $k ] = self::maybe_clone( $data );
            return true;
        }

        /**
         * Adds data to the cache, overwriting any existing data.
         *
         * $expire is ignored for non-persistent groups, because
         * they vanish at the end of script execution.
         */
        public function set(
            int|string $key,
            mixed $data,
            string $group = '',
            int $expire = 0,
        ): bool {
            if ( ! $this->can_add() ) {
                return false;
            }

            $k = $this->cache_key( $key, $group );

            if ( isset( $this->non_persistent_groups[ $group ] ) ) {
                $data = self::maybe_clone( $data );
                $this->non_persistent_groups[ $group ][ $k ] = $data;
                return true;
            }

            if ( $this->preseed_keys ) {
                $this->fetch_all();
            }

            $expire = self::to_memcache_expiration( $expire );
            $result = $this->mc->set( $k, $data, $expire );

            if ( $result === false ) {
                // We don't know the state of the memcached item
                unset( $this->local_cache[ $k ] );
                return false;
            }
            $this->local_cache[ $k ] = self::maybe_clone( $data );
            return true;
        }

        /**
         * Sets multiple items in the cache at once.
         *
         * $expire is ignored for non-persistent groups, because
         * they vanish at the end of script execution.
         */
        public function set_multiple(
            array $data,
            string $group = '',
            int $expire = 0,
        ): array {
            if ( ! $this->can_add() || $data === [] ) {
                return [];
            }

            if ( isset( $this->non_persistent_groups[ $group ] ) ) {
                $arr = [];
                foreach ( array_keys( $data ) as $key ) {
                    $k = $this->cache_key( $key, $group );
                    $data = self::maybe_clone( $data );
                    $this->non_persistent_groups[ $group ][ $k ] = $data;
                    $arr[ $key ] = true;
                }
                return $arr;
            }

            if ( $this->preseed_keys ) {
                $this->fetch_all();
            }

            $expire = self::to_memcache_expiration( $expire );
            $d = [];
            foreach ( $data as $key => $v ) {
                $k = $this->cache_key( $key, $group );
                $d[ $k ] = $v;
            }

            $result = $this->mc->setMulti( $d, $expire );

            $arr = [];
            if ( $result ) {
                foreach ( $data as $key => $v ) {
                    $k = $this->cache_key( $key, $group );
                    $this->local_cache[ $k ] = self::maybe_clone( $v );
                    $arr[ $key ] = true;
                }
            } else {
                foreach ( array_keys( $data ) as $key ) {
                    $k = $this->cache_key( $key, $group );
                    // We don't know the state of the memcached items
                    unset( $this->local_cache[ $k ] );
                    $arr[ $key ] = false;
                }
            }

            return $arr;
        }

        /**
         * Returns true if we support the given feature.
         *
         * See https://developer.wordpress.org/reference/functions/wp_cache_supports/
         */
        public function supports(
            string $feature,
        ): bool {
            return match ( $feature ) {
                'add_multiple',
                'delete_multiple',
                'flush_runtime',
                'get_multiple',
                'set_multiple'
                    => true,
                default => false,
            };
        }
    }

    if ( function_exists( 'wp_cache_add' ) ) {
        return;
    }

    function wp_cache_add(
        int|string $key,
        mixed $data,
        string $group = '',
        int $expire = 0,
    ): bool {
        global $wp_object_cache;
        return $wp_object_cache->add( $key, $data, $group, $expire );
    }

    function wp_cache_add_global_groups(
        string|array $groups,
    ): void {
        global $wp_object_cache;
        $groups = (array) $groups;
        $wp_object_cache->add_global_groups( $groups );
    }

    function wp_cache_add_multiple(
        array $data,
        string $group = '',
        int $expire = 0,
    ): array {
        global $wp_object_cache;
        return $wp_object_cache->add_multiple( $data, $group, $expire );
    }

    function wp_cache_add_non_persistent_groups(
        string|array $groups,
    ): void {
        global $wp_object_cache;
        $groups = (array) $groups;
        $wp_object_cache->add_non_persistent_groups( $groups );
    }

    function wp_cache_close(): bool {
        return true;
    }

    function wp_cache_decr(
        int|string $key,
        int $offset = 1,
        string $group = '',
    ): int|false {
        global $wp_object_cache;
        return $wp_object_cache->decr( $key, $offset, $group );
    }

    function wp_cache_delete(
        int|string $key,
        string $group = '',
    ): bool {
        global $wp_object_cache;
        return $wp_object_cache->delete( $key, $group );
    }

    function wp_cache_delete_multiple(
        array $keys,
        string $group = '',
    ): array {
        global $wp_object_cache;
        return $wp_object_cache->delete_multiple( $keys, $group );
    }

    function wp_cache_flush(): bool {
        global $wp_object_cache;
        return $wp_object_cache->flush();
    }

    function wp_cache_flush_runtime(): bool {
        global $wp_object_cache;
        return $wp_object_cache->flush_runtime();
    }

    function wp_cache_get(
        int|string $key,
        string $group = '',
        bool $force = false,
        ?bool &$found = null
    ): mixed {
        global $wp_object_cache;
        return $wp_object_cache->get( $key, $group, $force, $found );
    }

    function wp_cache_get_multiple(
        array $keys,
        string $group = '',
        bool $force = false,
    ): mixed {
        global $wp_object_cache;
        return $wp_object_cache->get_multiple( $keys, $group, $force );
    }

    function wp_cache_incr(
        int|string $key,
        int $offset = 1,
        string $group = '',
    ): int|false {
        global $wp_object_cache;
        return $wp_object_cache->incr( $key, $offset, $group );
    }

    function wp_cache_init(): void {
        global $wp_object_cache;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride
        $wp_object_cache = SnapCacheMemcached::initAndBuild();
        $wp_object_cache->get_seeds();
        wp_using_ext_object_cache( true );
    }

    function wp_cache_replace(
        int|string $key,
        mixed $data,
        string $group = '',
        int $expire = 0,
    ): bool {
        global $wp_object_cache;
        return $wp_object_cache->replace( $key, $data, $group, $expire );
    }

    function wp_cache_set(
        int|string $key,
        mixed $data,
        string $group = '',
        int $expire = 0,
    ): bool {
        global $wp_object_cache;
        return $wp_object_cache->set( $key, $data, $group, $expire );
    }

    function wp_cache_set_multiple(
        array $data,
        string $group = '',
        int $expire = 0,
    ): array {
        global $wp_object_cache;
        return $wp_object_cache->set_multiple( $data, $group, $expire );
    }

    function wp_cache_supports(
        string $feature,
    ): bool {
        global $wp_object_cache;
        return $wp_object_cache->supports( $feature );
    }

    wp_cache_init();
}
