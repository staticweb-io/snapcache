<?php declare(strict_types=1);

namespace SnapCache\CLI;

use WP_CLI;

class Args {
    /**
     * @param array<int, mixed> $args
     */
    public static function parse(
        array $args,
        ?array $assoc_args = null,
        ?array $opts = null,
        ?array $assoc_opts = null,
    ): array {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            WP_CLI::debug( 'Parsing CLI args: ' . json_encode( $args ) );
            WP_CLI::debug( 'CLI options: ' . json_encode( $opts ) );
            WP_CLI::debug( 'Parsing CLI assoc args: ' . json_encode( $assoc_args ) );
            WP_CLI::debug( 'CLI assoc options: ' . json_encode( $assoc_opts ) );
        }

        $cfg = [];

        $n_args = count( $args );
        $opts_keys = array_keys( $opts ?? [] );
        for ( $i = 0; $i < $n_args; $i++ ) {
            $k = $opts_keys[ $i ] ?? null;
            if ( $k === null ) {
                WP_CLI::error( 'Too many arguments' );
            }
            $cfg[ $k ] = $args[ $i ];
        }

        foreach ( $assoc_args ?? [] as $k => $v ) {
            if ( ! array_key_exists( $k, $assoc_opts ?? [] ) ) {
                WP_CLI::error( 'Unrecognized option: --' . $k );
            }

            $cfg[ $k ] = $v;
        }

        foreach ( $assoc_opts ?? [] as $k => $v ) {
            if ( ! array_key_exists( $k, $cfg ) ) {
                if ( $v && array_key_exists( 'default', $v ) ) {
                    $cfg[ $k ] = $v['default'];
                } elseif ( $v['required'] ?? false ) {
                    WP_CLI::error( 'Missing required option: --' . $k );
                } else {
                    $cfg[ $k ] = null;
                }
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            WP_CLI::debug( 'Parsed CLI args: ' . json_encode( $cfg ) );
        }

        return $cfg;
    }
}
