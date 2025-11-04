<?php

namespace SnapCache\CLI;

use WP_CLI;

class Base {
    public static function init(): void {
        WP_CLI::add_command( self::getName(), self::class );
        Memcached::registerCommands();
    }

    public static function getName(): string {
        return 'snapcache';
    }
}
