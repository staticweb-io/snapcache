<?php
use SnapCache\MemcachedStats;

$result = MemcachedStats::get();

t_assert_equals(
    true,
    array_key_exists( 'servers', $result ) && ! array_key_exists( 'error', $result ),
    'MemcachedStats::get() succeeds against the live dev memcached server'
);

t_assert_equals( 1, count( $result['servers'] ), 'there is exactly one configured server' );

$server = $result['servers'][0];

t_assert_equals( true, ! isset( $server['error'] ), 'the live server has no error' );

t_assert_equals(
    true,
    str_contains( $server['server'], '11212' ),
    'the server key includes the configured port'
);

t_assert_equals(
    1,
    preg_match( '/^\d+\.\d+\.\d+/', $server['version'] ),
    'the server reports a semantic version'
);

t_assert_equals(
    [ 'Hit Rate', 'Memory', 'Uptime' ],
    array_column( $server['cards'], 'label' ),
    'the server has the expected cards in order'
);

// Basic sanity checks on the raw numbers backing the cards.
$raw = $server['raw'];

t_assert_equals(
    true,
    (int) $raw['get_hits'] >= 0,
    'get_hits is non-negative'
);
t_assert_equals(
    true,
    (int) $raw['get_misses'] >= 0,
    'get_misses is non-negative'
);
t_assert_equals(
    true,
    (int) $raw['bytes'] >= 0,
    'bytes used is non-negative'
);
t_assert_equals(
    true,
    (int) $raw['curr_items'] >= 0,
    'curr_items is non-negative'
);
t_assert_equals(
    true,
    (int) $raw['uptime'] >= 0,
    'uptime is non-negative'
);

[ $hit_rate_card, $memory_card, $uptime_card ] = $server['cards'];

// Hit Rate meter, when present, is a percentage.
if ( $hit_rate_card['meter'] !== null ) {
    t_assert_equals(
        true,
        $hit_rate_card['meter']['value'] >= 0.0 && $hit_rate_card['meter']['value'] <= 100.0,
        'hit rate meter value is between 0 and 100'
    );
}

// Memory meter, when present, is bounded by the configured limit.
if ( $memory_card['meter'] !== null ) {
    t_assert_equals(
        true,
        $memory_card['meter']['value'] >= 0.0
            && $memory_card['meter']['value'] <= $memory_card['meter']['max'],
        'memory meter value is between 0 and the configured limit'
    );
}

t_assert_equals(
    true,
    $uptime_card['value'] !== 'N/A',
    'uptime is reported since the server has been running'
);
