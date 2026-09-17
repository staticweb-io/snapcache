<?php declare(strict_types=1);

namespace SnapCache\CLI;

use SnapCache\MemcachedStats;
use WP_CLI;

/**
 * Internal debugging commands. These are unstable and subject
 * to change.
 *
 * These commands are mainly used to test the data paths used by the
 * admin UI, but they can also be used to diagnose issues. Consider
 * adding a new CLI command rather than relying on one of these.
 */
class DebugInternal {
    public static function registerCommands(): void {
        Subcommand::register(
            'debug _internal',
            self::class,
        );
    }

    /**
     * Get memcached stats summarized as the cards shown in the
     * admin UI, including any error conditions.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : The format to output the stats data in.
     * ---
     * default: json
     * options:
     *  - json
     *  - yaml
     * ---
     */
    public function memcached_stats( array $args, array $assoc_args ): void {
        $cfg = Args::parse(
            $args,
            $assoc_args,
            [],
            [ 'format' => [ 'default' => 'json' ] ],
        );

        WP_CLI::print_value( MemcachedStats::get(), $cfg );
    }
}
