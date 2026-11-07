<?php
/**
 * Repeatedly reads a key through PHP-FPM, filling APCu at the same time as a
 * test writes to it, until the stop file exists.
 *
 * Usage: php reader.php URL KEY STOP_FILE
 */

// phpcs:disable

[ , $url, $key, $stop_file ] = $argv;

$context = stream_context_create(
    [
        'http' => [
            'content' => json_encode( [ 'ops' => [ [ 'get_uncached', $key ] ] ] ),
            'header' => 'Content-Type: application/json',
            'ignore_errors' => true,
            'method' => 'POST',
        ],
    ]
);

while ( ! file_exists( $stop_file ) ) {
    file_get_contents( $url, false, $context );
}
