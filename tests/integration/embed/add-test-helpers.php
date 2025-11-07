<?php
/**
 * Since the WP testing framework only supports a very
 * ancient version of PHPUnit, we don't use it and
 * instead create a small testing system using
 * `wp eval`.
 */

function t_assert_equals( $expected, $actual, $message = '' ) {
    if ( $expected !== $actual ) {
        \WP_CLI::error( $message );
    }
}
