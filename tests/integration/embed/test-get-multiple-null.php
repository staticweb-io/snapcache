<?php
$k = uniqid();
$k2 = uniqid();
$group = uniqid();

t_assert_equals( true, wp_cache_set( $k, null, $group ), 'wp_cache_set stores null' );
t_assert_equals( true, wp_cache_set( $k2, 'v', $group ), 'wp_cache_set stores a string' );

$expected = [
    $k => null,
    $k2 => 'v',
];

t_assert_equals(
    $expected,
    wp_cache_get_multiple( [ $k, $k2 ], $group ),
    'wp_cache_get_multiple returns null from the local cache'
);

wp_cache_flush_runtime();
t_assert_equals(
    $expected,
    wp_cache_get_multiple( [ $k, $k2 ], $group ),
    'wp_cache_get_multiple returns null from memcached'
);

wp_cache_flush_runtime();
wp_cache_get( $k2, $group );
t_assert_equals(
    $expected,
    wp_cache_get_multiple( [ $k, $k2 ], $group ),
    'wp_cache_get_multiple returns null from memcached alongside a local value'
);

t_assert_equals(
    $expected,
    wp_cache_get_multiple( [ $k, $k2 ], $group, true ),
    'wp_cache_get_multiple returns null when forced'
);
