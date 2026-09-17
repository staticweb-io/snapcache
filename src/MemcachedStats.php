<?php declare(strict_types=1);

namespace SnapCache;

/**
 * Builds the memcached stats summary data used in the UI.
 */
class MemcachedStats {
    /**
     * @return array{
     *   error?: string,
     *   servers?: array<int, array{
     *     server: string,
     *     version?: string,
     *     error?: string,
     *     cards?: array<int, array{
     *       label: string,
     *       value: string,
     *       sub: string,
     *       meter: array{value: float, max: float}|null,
     *     }>,
     *     raw?: array<string, mixed>,
     *   }>,
     * }
     */
    public static function get(): array {
        if ( ! Memcached::extensionAvailable() ) {
            return [ 'error' => 'The Memcached PHP extension is not installed.' ];
        }

        $mc = Memcached::getMemcached();
        if ( ! $mc instanceof \Memcached ) {
            return [ 'error' => 'Memcached is not enabled.' ];
        }

        $all_stats = $mc->getStats();

        if ( $all_stats === false || $all_stats === [] ) {
            return [ 'error' => 'Could not retrieve stats. No Memcached servers responded.' ];
        }

        $servers = [];
        foreach ( $all_stats as $server => $server_stats ) {
            $servers[] = self::buildServerData( (string) $server, $server_stats );
        }

        return [ 'servers' => $servers ];
    }

    /**
     * Converts a single server's raw memcached stats (as returned by
     * \Memcached::getStats()) into the card data shown in the UI.
     *
     * @param array<string, mixed>|false $stats
     * @return array{
     *   server: string,
     *   version?: string,
     *   error?: string,
     *   cards?: array<int, array{
     *     label: string,
     *     value: string,
     *     sub: string,
     *     meter: array{value: float, max: float}|null,
     *   }>,
     *   raw?: array<string, mixed>,
     * }
     */
    public static function buildServerData( string $server, $stats ): array {
        if ( $stats === false || ! is_array( $stats ) || $stats === [] ) {
            return [
                'server' => $server,
                'error' => 'Connection failed.',
            ];
        }

        $get_hits = (int) ( $stats['get_hits'] ?? 0 );
        $get_misses = (int) ( $stats['get_misses'] ?? 0 );
        $get_total = $get_hits + $get_misses;
        $hit_rate = $get_total > 0
            ? round( ( $get_hits / $get_total ) * 100, 1 )
            : null;

        $bytes_used = (int) ( $stats['bytes'] ?? 0 );
        $bytes_limit = (int) ( $stats['limit_maxbytes'] ?? 0 );
        $memory_pct = $bytes_limit > 0
            ? round( ( $bytes_used / $bytes_limit ) * 100, 1 )
            : null;

        $hit_rate_sub = $get_total > 0
            ? number_format( $get_hits ) . ' hits / ' . number_format( $get_total ) . ' gets'
            : 'No requests yet';

        return [
            'server' => $server,
            'version' => (string) ( $stats['version'] ?? 'unknown' ),
            'cards' => [
                [
                    'label' => 'Hit Rate',
                    'value' => $hit_rate !== null ? $hit_rate . '%' : 'N/A',
                    'sub' => $hit_rate_sub,
                    'meter' => $hit_rate !== null
                        ? [
                            'value' => $hit_rate,
                            'max' => 100.0,
                        ]
                        : null,
                ],
                [
                    'label' => 'Memory',
                    'value' => $memory_pct !== null
                        ? $memory_pct . '% (' . size_format( $bytes_used, 1 ) . ')'
                        : size_format( $bytes_used, 1 ),
                    'sub' => ( $memory_pct !== null
                        ? $memory_pct . '% of ' . size_format( $bytes_limit, 1 ) . ', '
                        : '' )
                        . number_format( (int) ( $stats['curr_items'] ?? 0 ) ) . ' items',
                    'meter' => $bytes_limit > 0
                        ? [
                            'value' => (float) $bytes_used,
                            'max' => (float) $bytes_limit,
                        ]
                        : null,
                ],
                [
                    'label' => 'Uptime',
                    'value' => isset( $stats['uptime'] )
                        ? self::formatUptime( (int) $stats['uptime'] )
                        : 'N/A',
                    'sub' => isset( $stats['uptime'] )
                        ? 'since ' . self::formatSince( (int) $stats['uptime'] )
                        : '',
                    'meter' => null,
                ],
            ],
            'raw' => $stats,
        ];
    }

    private static function formatUptime( int $seconds ): string {
        return human_time_diff( time() - $seconds );
    }

    private static function formatSince( int $uptime_seconds ): string {
        $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

        return wp_date( $format, time() - $uptime_seconds );
    }
}
