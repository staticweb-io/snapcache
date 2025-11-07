<?php
$expiration = 600;
$groups = [ '', 'default', uniqid() ];

foreach ( $groups as $group ) {
    $p = uniqid();
    $pv = uniqid();
    $p2 = uniqid();
    $pv2 = uniqid();

    t_assert_equals(
        false,
        wp_cache_get( $p, $group ),
        'wp_cache_get returns false when key does not exist'
    );

    t_assert_equals(
        true,
        wp_cache_set( $p, $pv, $group, $expiration ),
        'wp_cache_set returns true on success'
    );

    t_assert_equals(
        $pv,
        wp_cache_get( $p, $group ),
        'wp_cache_get returns the value it was set to'
    );

    t_assert_equals(
        false,
        wp_cache_add( $p, uniqid(), $group, $expiration ),
        'wp_cache_add returns false when the key already exists'
    );
    t_assert_equals(
        $pv,
        wp_cache_get( $p, $group ),
        'wp_cache_get returns the original value after failed add'
    );

    t_assert_equals(
        false,
        wp_cache_replace( $p2, $pv2, $group, $expiration ),
        'wp_cache_replace returns false when the key does not exist'
    );
    t_assert_equals(
        false,
        wp_cache_get( $p2, $group ),
        'wp_cache_get returns false after failed replace'
    );

    t_assert_equals(
        true,
        wp_cache_add( $p2, $pv2, $group, $expiration ),
        'wp_cache_add returns true when the key did not exist'
    );
    t_assert_equals(
        $pv2,
        wp_cache_get( $p2, $group ),
        'wp_cache_get returns the correct value after add'
    );

    $v = uniqid();
    t_assert_equals(
        true,
        wp_cache_replace( $p2, $v, $group, $expiration ),
        'wp_cache_replace returns true when the key already exists with different value'
    );
    t_assert_equals(
        $v,
        wp_cache_get( $p2, $group ),
        'wp_cache_get returns the correct value after replace'
    );
    t_assert_equals(
        true,
        wp_cache_replace( $p2, $v, $group, $expiration ),
        'wp_cache_replace returns true when the key already exists with same value'
    );
}
