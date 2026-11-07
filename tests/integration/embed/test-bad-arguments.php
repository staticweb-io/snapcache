<?php

// WordPress core tolerates these, so the site must keep working when
// a theme or plugin passes them.

wp_cache_set( 'bad-args', 'v' );

$cases = [
    'empty key' => [ fn() => wp_cache_get( '' ), false ],
    'empty key set' => [ fn() => wp_cache_set( '', 'v' ), false ],
    'empty key add' => [ fn() => wp_cache_add( '', 'v' ), false ],
    'empty key delete' => [ fn() => wp_cache_delete( '' ), false ],
    'null key' => [ fn() => wp_cache_get( null ), false ],
    'array key' => [ fn() => wp_cache_set( [ 'a' ], 'v' ), false ],
    'empty key in multiple' => [ fn() => wp_cache_get_multiple( [ '' ] ), [] ],
    'not an array' => [ fn() => wp_cache_get_multiple( 'a' ), [] ],
    'numeric string expiration' => [ fn() => wp_cache_set( 'bad-args', 'v2', '', '60' ), true ],
    'numeric string read' => [ fn() => wp_cache_get( 'bad-args' ), 'v2' ],
    'null group' => [ fn() => wp_cache_get( 'bad-args', null ), false ],
];

foreach ( $cases as $name => [ $call, $expected ] ) {
    t_assert_equals( $expected, $call(), $name );
}
