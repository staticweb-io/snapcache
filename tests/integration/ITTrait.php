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
                $this->wpCli(
                    [
                        'plugin',
                        'uninstall',
                        $plugin['name'],
                        '--deactivate',
                        '--skip-delete',
                    ]
                );
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
     * Evaluate a string expression in the context of WP_CLI.
     * Since the WP testing framework only supports a very
     * ancient version of PHPUnit, we don't use it and
     * instead create a small testing system using
     * `wp eval`.
     */
    public function evalEmbeddedTest( string $test_filename ): void {
        $test_helpers = file_get_contents( __DIR__ . '/embed/add-test-helpers.php' );
        $test_helpers = preg_replace( '/^<\?php\s*/', '', $test_helpers );
        $teststr = file_get_contents( __DIR__ . '/embed/' . $test_filename );
        $teststr = preg_replace( '/^<\?php\s*/', '', $teststr );
        $this->wpCli( [ 'eval', $test_helpers . $teststr ] );
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
