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

    /**
     * Returns a socket to a server in the memcached pool
     *
     * @param int &$error_code Error code passed to fsockopen
     * @param string &$error_message Error message passed to fsockopen
     */
    public static function getSocket(
        \Memcached $mc,
        ?int &$error_code = null,
        ?string &$error_message = null,
    ) {
        $server = $mc->getServerList()[0] ?? null;
        if ( $server === null ) {
            return null;
        }

        $host = $server['host'];
        $port = $server['port'];

        return stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $error_code,
            $error_message,
            1.0
        );
    }

    /**
     * Execute a command over the memcached text protocol.
     * Returns an \Iterator of the result lines.
     */
    public static function doCommand(
        \Memcached $mc,
        string $command,
    ): \Iterator {
        $error_code = null;
        $error_message = null;
        $sock = self::getSocket( $mc, $error_code, $error_message );
        if ( ! $sock ) {
            $msg = 'Failed to connect to Memcached: ' .
                $error_code . ' ' . $error_message;
            throw new SnapCacheException( esc_html( $msg ) );
        }

        $result = stream_socket_sendto( $sock, $command . "\r\n" );
        if ( ! $result ) {
            stream_socket_shutdown( $sock, STREAM_SHUT_RDWR );
            throw new SnapCacheException( 'Failed to send message to Memcached' );
        }

        while ( ! feof( $sock ) ) {
            $line = fgets( $sock );
            if ( $line === false || rtrim( $line ) === 'END' ) {
                return;
            }
            yield $line;
        }

        $result = stream_socket_shutdown( $sock, STREAM_SHUT_RDWR );
        if ( ! $result ) {
            throw new SnapCacheException( 'Memcached socket shutdown failed' );
        }
    }

    public static function parseItem( string $line ): array {
        $line = trim( $line );
        $pairs = explode( ' ', $line );
        $arr = [];
        foreach ( $pairs as $pair ) {
            [$k, $v] = explode( '=', $pair, 2 );
            $arr[ $k ] = $v;
        }
        return $arr;
    }

    /**
     * Returns the output of lru_crawler metadump
     * See https://github.com/memcached/memcached/blob/master/doc/protocol.txt
     */
    public static function metadump(
        \Memcached $mc,
    ): \Iterator {
        $lines = self::doCommand(
            $mc,
            'lru_crawler metadump all'
        );
        foreach ( $lines as $line ) {
            yield self::parseItem( $line );
        }
    }
}
