<?php
use SnapCache\MemcachedStats;

// Connection failure: raw stats is false.
$result = MemcachedStats::buildServerData( 'down.example:11211', false );
t_assert_equals(
    [
        'server' => 'down.example:11211',
        'error' => 'Connection failed.',
    ],
    $result,
    'buildServerData reports a connection failure when stats is false'
);

// Connection failure: raw stats is an empty array.
$result = MemcachedStats::buildServerData( 'down.example:11211', [] );
t_assert_equals(
    [
        'server' => 'down.example:11211',
        'error' => 'Connection failed.',
    ],
    $result,
    'buildServerData reports a connection failure when stats is empty'
);

// Full stats: normal operation.
$stats = [
    'get_hits' => 80,
    'get_misses' => 20,
    'bytes' => 368640,
    'limit_maxbytes' => 104857600,
    'curr_items' => 42,
    'uptime' => 3661,
    'version' => '1.6.29',
];
$result = MemcachedStats::buildServerData( '127.0.0.1:11212', $stats );

t_assert_equals( '127.0.0.1:11212', $result['server'], 'server is passed through' );
t_assert_equals( '1.6.29', $result['version'], 'version is taken from stats' );
t_assert_equals( $stats, $result['raw'], 'raw stats are passed through unchanged' );
t_assert_equals( 3, count( $result['cards'] ), 'there are 3 cards' );

$hit_rate = $result['cards'][0];
t_assert_equals( 'Hit Rate', $hit_rate['label'], 'card 0 is Hit Rate' );
t_assert_equals( '80%', $hit_rate['value'], 'hit rate value is calculated from hits/misses' );
t_assert_equals( '80 hits / 100 gets', $hit_rate['sub'], 'hit rate sub shows hits/gets' );
t_assert_equals(
    [
        'value' => 80.0,
        'max' => 100.0,
    ],
    $hit_rate['meter'],
    'hit rate has a meter'
);

$memory = $result['cards'][1];
t_assert_equals( 'Memory', $memory['label'], 'card 1 is Memory' );
t_assert_equals(
    '0.4% (' . size_format( 368640, 1 ) . ')',
    $memory['value'],
    'memory value shows percent used and bytes used'
);
t_assert_equals(
    '0.4% of ' . size_format( 104857600, 1 ) . ', 42 items',
    $memory['sub'],
    'memory sub shows percent of limit and item count'
);
t_assert_equals(
    [
        'value' => 368640.0,
        'max' => 104857600.0,
    ],
    $memory['meter'],
    'memory has a meter'
);

$uptime = $result['cards'][2];
t_assert_equals( 'Uptime', $uptime['label'], 'card 2 is Uptime' );
t_assert_equals(
    human_time_diff( time() - 3661 ),
    $uptime['value'],
    'uptime value is human readable'
);
t_assert_equals(
    true,
    str_starts_with( $uptime['sub'], 'since ' ),
    'uptime sub starts with "since "'
);
t_assert_equals( null, $uptime['meter'], 'uptime has no meter' );

// No requests yet, no memory limit configured, no uptime reported.
$stats_minimal = [
    'get_hits' => 0,
    'get_misses' => 0,
    'bytes' => 0,
    'limit_maxbytes' => 0,
    'curr_items' => 0,
    'version' => '1.6.29',
];
$result = MemcachedStats::buildServerData( 'server', $stats_minimal );

t_assert_equals( 'N/A', $result['cards'][0]['value'], 'hit rate is N/A with no requests' );
t_assert_equals(
    'No requests yet',
    $result['cards'][0]['sub'],
    'hit rate sub explains no requests'
);
t_assert_equals( null, $result['cards'][0]['meter'], 'hit rate has no meter with no requests' );

t_assert_equals(
    size_format( 0, 1 ),
    $result['cards'][1]['value'],
    'memory value is just the size when there is no limit'
);
t_assert_equals(
    '0 items',
    $result['cards'][1]['sub'],
    'memory sub is just the item count when there is no limit'
);
t_assert_equals( null, $result['cards'][1]['meter'], 'memory has no meter when there is no limit' );

t_assert_equals( 'N/A', $result['cards'][2]['value'], 'uptime value is N/A when not reported' );
t_assert_equals( '', $result['cards'][2]['sub'], 'uptime sub is empty when not reported' );

// No version reported.
$stats_no_version = $stats_minimal;
unset( $stats_no_version['version'] );
$result = MemcachedStats::buildServerData( 'server', $stats_no_version );
t_assert_equals( 'unknown', $result['version'], 'version defaults to unknown' );
