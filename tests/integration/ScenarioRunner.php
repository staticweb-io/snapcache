<?php declare(strict_types=1);

namespace SnapCache;

use Yosymfony\Toml\Toml;

class ScenarioRunner {
    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function loadAll( string $dir ): array {
        $files = glob( $dir . '/*.toml' );
        $files = false !== $files ? $files : [];
        sort( $files );
        $result = [];
        foreach ( $files as $file ) {
            $name = basename( $file, '.toml' );
            $result[ $name ] = [ Toml::parseFile( $file ) ];
        }
        return $result;
    }

    /**
     * @param array<mixed> $scenario
     */
    public static function buildPhp( array $scenario ): string {
        $php = "wp_cache_flush();\n";
        foreach ( $scenario['steps'] ?? [] as $i => $step ) {
            $php .= self::buildStepPhp( $i + 1, $step );
        }
        return $php;
    }

    /**
     * @param array<mixed> $step
     */
    private static function buildStepPhp( int $num, array $step ): string {
        $do = $step['do'] ?? throw new \RuntimeException( "Step {$num} missing 'do'" );
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
        $key = isset( $step['key'] ) ? var_export( (string) $step['key'], true ) : null;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
        $value = isset( $step['value'] ) ? var_export( $step['value'], true ) : null;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
        $group = var_export( (string) ( $step['group'] ?? '' ), true );
        $expiration = (int) ( $step['expiration'] ?? 0 );

        $call = match ( $do ) {
            'set'     => "wp_cache_set({$key}, {$value}, {$group}, {$expiration})",
            'get'     => "wp_cache_get({$key}, {$group})",
            'add'     => "wp_cache_add({$key}, {$value}, {$group}, {$expiration})",
            'replace' => "wp_cache_replace({$key}, {$value}, {$group}, {$expiration})",
            'delete'  => "wp_cache_delete({$key}, {$group})",
            'flush'   => 'wp_cache_flush()',
            default   => throw new \RuntimeException( "Unknown operation: {$do}" ),
        };

        $php = "\$_r = {$call};\n";

        if ( array_key_exists( 'returns', $step ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
            $expected = var_export( $step['returns'], true );
            $label    = addslashes( "Step {$num} ({$do})" );
            $php .= "if (\$_r !== {$expected}) {\n";
            $php .= "  \\WP_CLI::error(\"{$label}: expected \" . var_export({$expected}, true)"
                . " . \", got \" . var_export(\$_r, true));\n";
            $php .= "}\n";
        }

        return $php;
    }
}
