<?php declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export

namespace SnapCache;

/**
 * Runs cache operations in PHP-FPM and WP-CLI with fpm/endpoint.php.
 */
trait FpmTrait {
    private function endpointFile(): string {
        return ITEnv::getWordPressDir() . '/snapcache-test-endpoint.php';
    }

    private function endpointUrl(): string {
        return ITEnv::getSiteUrl() . '/snapcache-test-endpoint.php';
    }

    private function installFpmEndpoint(): void {
        copy( __DIR__ . '/fpm/endpoint.php', $this->endpointFile() );
    }

    private function removeFpmEndpoint(): void {
        unlink( $this->endpointFile() );
    }

    private function curlHandle( array $ops ): \CurlHandle {
        $handle = curl_init( $this->endpointUrl() );
        curl_setopt_array(
            $handle,
            [
                CURLOPT_POSTFIELDS => json_encode( [ 'ops' => $ops ] ),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]
        );
        return $handle;
    }

    /**
     * Runs ops in one PHP-FPM request and returns their results.
     */
    private function fpm( array $ops ): array {
        $body = curl_exec( $this->curlHandle( $ops ) );
        return $this->decodeResults( is_string( $body ) ? $body : '' );
    }

    /**
     * Runs ops in one WP-CLI process and returns their results.
     */
    private function cli( array $ops ): array {
        $file = var_export( $this->endpointFile(), true );
        $input = var_export( json_encode( $ops ), true );
        $result = $this->wpCli(
            [
                'eval',
                "require {$file}; echo json_encode( array_map("
                . " snapcache_test_run_op( ... ), json_decode( {$input}, true ) ) );",
            ]
        );
        return $this->decodeResults( (string) $result['final_line'] );
    }

    /**
     * Runs ops with a given PHP command, loading WordPress without WP-CLI.
     */
    private function php( string $php, array $ops ): array {
        $cmd = $php . ' ' . escapeshellarg( $this->endpointFile() ) . ' '
            . escapeshellarg( (string) json_encode( [ 'ops' => $ops ] ) );
        $output = [];
        $exit_code = 0;
        exec( $cmd . ' 2>&1', $output, $exit_code );
        $this->assertSame( 0, $exit_code, $cmd . "\n" . implode( "\n", $output ) );
        return $this->decodeResults( (string) end( $output ) );
    }

    private function decodeResults( string $body ): array {
        $results = json_decode( $body, true );
        $this->assertIsArray( $results, 'Not JSON: ' . $body );
        $this->assertArrayNotHasKey( 'error', $results, $body );
        return $results;
    }

    /**
     * Starts processes that keep reading a key through PHP-FPM.
     * Returns a function that stops them.
     */
    private function startReaders( string $key, int $count = 4 ): callable {
        $stop_file = tempnam( sys_get_temp_dir(), 'snapcache-readers' );
        unlink( $stop_file );
        $processes = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $processes[] = proc_open(
                [ PHP_BINARY, __DIR__ . '/fpm/reader.php', $this->endpointUrl(), $key, $stop_file ],
                [],
                $pipes,
            );
        }
        return function () use ( $processes, $stop_file ): void {
            touch( $stop_file );
            foreach ( $processes as $process ) {
                proc_close( $process );
            }
            unlink( $stop_file );
        };
    }
}
