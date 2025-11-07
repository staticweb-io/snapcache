<?php declare(strict_types=1);

namespace SnapCache;

/**
 * Integration test environment helpers
 */
class ITEnv {
    private static string $wordpress_dir;

    public static function getPluginsDir(): string {
        return self::getWordPressDir() . '/wp-content/plugins';
    }

    public static function getWordPressDir(): string
    {
        if ( ! isset( self::$wordpress_dir ) ) {
            $wordpress_dir = getenv( 'WORDPRESS_DIR' );
            if ( ! $wordpress_dir ) {
                throw new \RuntimeException( 'WORDPRESS_DIR environment variable not set' );
            }
            self::$wordpress_dir = rtrim( $wordpress_dir, '/' );
        }
        return self::$wordpress_dir;
    }
}
