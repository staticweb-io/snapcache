<?php declare(strict_types=1);

namespace SnapCache;

/**
 * Integration test helper trait
 */
trait ITTrait {
    public function setUp(): void {
        $plugins = $this->wpCli( [ 'plugin', 'list', '--format=json' ] )['final_line'];
        foreach ( json_decode( (string) $plugins, true ) as $plugin ) {
            if ( $plugin['name'] !== 'snapcache' && $plugin['status'] !== 'dropin' ) {
                $this->wpCli( [ 'plugin', 'deactivate', $plugin['name'] ] );
                $this->wpCli( [ 'plugin', 'uninstall', $plugin['name'] ] );
            }
        }

        $this->wpCli( [ 'plugin', 'activate', 'snapcache' ] );
    }

    /**
     * @return array<string, int|string|list<string>|null>
     */
    public function wpCli( array $args, array $expect_warnings = [] ): array
    {
        $wordpress_dir = ITEnv::getWordPressDir();
        $cmd = implode(
            ' ',
            array_map(
                escapeshellarg( ... ),
                array_merge( [ 'wp', '--path=' . $wordpress_dir ], $args )
            )
        );
        $output = [];
        $exit_code = 0;
        exec( $cmd . ' 2>&1', $output, $exit_code );

        foreach ( $expect_warnings as $pattern => $expected_count ) {
            $matches = array_filter(
                $output,
                fn( string $line ): bool => preg_match( $pattern, $line ) === 1,
            );
            $this->assertCount(
                $expected_count,
                $matches,
                "Expected {$expected_count} matches for pattern: {$pattern}"
            );
        }

        $this->assertSame(
            0,
            $exit_code,
            "WP CLI command failed: {$cmd}\nOutput: " . implode( "\n", $output )
        );

        return [
            'exit' => $exit_code,
            'final_line' => $output === [] ? null : $output[ count( $output ) - 1 ],
            'output' => $output,
        ];
    }

    public function pluginCli( array $args, array $expect_warnings = [] ): array {
        return $this->wpCli( [ 'snapcache', ...$args ], $expect_warnings );
    }

    /**
     * Install a plugin from a string
     */
    public function installStringPlugin(
        string $plugin_name,
        string $plugin_content,
    ): void {
        $plugin_dir = ITEnv::getPluginsDir() . '/' . $plugin_name;
        $result = exec( 'mkdir -p ' . escapeshellarg( $plugin_dir ) );
        $this->assertNotFalse( $result );
        $plugin_filename = $plugin_dir . '/' . $plugin_name . '.php';
        $written = file_put_contents( $plugin_filename, $plugin_content );
        $this->assertNotFalse( $written );
        $this->wpCli( [ 'plugin', 'activate', $plugin_name ] );
    }
}
