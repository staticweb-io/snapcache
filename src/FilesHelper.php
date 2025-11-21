<?php

namespace SnapCache;

class FilesHelper {
    /**
     * Whether WP_Filesystem has been initialized.
     * true if successful, false if failed,
     * and null if not attempted yet.
     */
    private static ?bool $wpfs_initialized = null;

    /**
     * Copy a file
     */
    public static function copyFile(
        string $from,
        string $to,
    ): void {
        $directory = dirname( $to );
        self::createDir( $directory );

        if ( ! defined( 'SNAPCACHE_DIRECT_FILE_ACCESS' )
        || ! SNAPCACHE_DIRECT_FILE_ACCESS ) {
            self::initFS( null, true );
            global $wp_filesystem;
            $result = $wp_filesystem->copy( $from, $to, true );
        } else {
            $result = copy( $from, $to );
        }

        if ( $result === false ) {
            $msg = 'Unable to write file ' . $to;
            throw new SnapCacheException( esc_html( $msg ) );
        }
    }

    /**
     * Creates a directory at path and any parent directories that
     * don't already exist. Returns true if the directory already
     * exists.
     *
     * @return bool true if the directory was created or already exists.
     *   false otherwise.
     */
    public static function createDir(
        string $directory,
    ): bool {
        if ( ! defined( 'SNAPCACHE_DIRECT_FILE_ACCESS' )
        || ! SNAPCACHE_DIRECT_FILE_ACCESS ) {
            return wp_mkdir_p( $directory );
        }

        // @phpcs:disable WordPress.PHP.NoSilencedErrors
        if ( @file_exists( $directory ) ) {
            // @phpcs:disable WordPress.PHP.NoSilencedErrors
            return @is_dir( $directory );
        }
        // @phpcs:disable WordPress.PHP.NoSilencedErrors
        $result = @mkdir( $directory, 0775, true );
        if ( $result ) {
            return true;
        }
        // @phpcs:disable WordPress.PHP.NoSilencedErrors
        if ( @file_exists( $directory ) ) {
            // @phpcs:disable WordPress.PHP.NoSilencedErrors
            return @is_dir( $directory );
        }
        return false;
    }

    /**
     * Recursively delete a directory
     *
     * @throws StaticDeployException
     */
    public static function deleteDirWithFiles( string $dir ): void {
        if ( ! defined( 'SNAPCACHE_DIRECT_FILE_ACCESS' )
        || ! SNAPCACHE_DIRECT_FILE_ACCESS ) {
            global $wp_filesystem;
            self::initFS( null, true );
            $wp_filesystem->delete( $dir, true, 'd' );
        } elseif ( is_dir( $dir ) ) {
            $dir_files = scandir( $dir );

            if ( ! $dir_files ) {
                $msg = 'Trying to delete nonexistent dir: ' . $dir;
                throw new SnapCacheException( $msg );
            }

            $files = array_diff( $dir_files, [ '.', '..' ] );

            foreach ( $files as $file ) {
                ( is_dir( "{$dir}/{$file}" ) ) ?
                self::deleteDirWithFiles( "{$dir}/{$file}" ) :
                self::deleteFile( "{$dir}/{$file}" );
            }

            rmdir( $dir );
        }
    }

    /**
     * Delete a file.
     *
     * @param string $filename Path to the file.
     */
    public static function deleteFile( string $filename ): void {
        if ( ! defined( 'SNAPCACHE_DIRECT_FILE_ACCESS' )
        || ! SNAPCACHE_DIRECT_FILE_ACCESS ) {
            $result = wp_delete_file( $filename );
        } else {
            // @phpcs:disable WordPress.PHP.NoSilencedErrors
            $result = @unlink( $filename );
        }

        if ( ! $result ) {
            $msg = 'Failed to delete file: ' . $filename;
            throw new SnapCacheException( esc_html( $msg ) );
        }
    }

    /**
     * Initialize the WP_Filesystem
     *
     * @param ?string $form_action_url The URL that the credentials form will
     *   be submitted to, if a credentials form is needed. Provide null if
     *   calling from a context outside of the WP Admin.
     * @return bool true on success, false on failure.
     */
    public static function initFS(
        ?string $form_action_url,
        bool $throw_on_failure = false,
    ): bool {
        if ( isset( self::$wpfs_initialized ) ) {
            $init = self::$wpfs_initialized;
            if ( $throw_on_failure && ! $init ) {
                throw new SnapCacheException( 'WP_Filesystem failed to initialize' );
            }
            return $init;
        }

        $init_wpfs = function ( array|bool $creds ) use ( $throw_on_failure ): bool {
            if ( $creds === true ) {
                $init = WP_Filesystem();
            } elseif ( $creds === false ) {
                $init = false;
            } else {
                $init = WP_Filesystem( $creds );
            }
            if ( $init === null ) {
                $init = false;
            }
            self::$wpfs_initialized = $init;
            if ( $throw_on_failure && ! $init ) {
                throw new SnapCacheException( 'WP_Filesystem failed to initialize' );
            }
            return $init;
        };

        if ( $form_action_url === null ) {
            if ( get_filesystem_method() === 'direct' ) {
                return $init_wpfs( true );
            }
            throw new SnapCacheException(
                "Can't initialize WP_Filesystem without credentials",
            );
        }

        $creds = request_filesystem_credentials( $form_action_url, '', false, '', null );
        if ( $creds === false ) {
            $creds = request_filesystem_credentials( $form_action_url, '', true, '', null );
        }
        return $init_wpfs( $creds );
    }

    /**
     * Returns true if the filename exists and is writable.
     * The filename may refer to either a directory or a file.
     */
    public static function isWriteable(
        string $filename,
    ): bool {
        if ( ! defined( 'SNAPCACHE_DIRECT_FILE_ACCESS' )
        || ! SNAPCACHE_DIRECT_FILE_ACCESS ) {
            return wp_is_writable( $filename );
        }
        return is_writable( $filename );
    }

    /**
     * Returns a normalized path, resolving . and .. when possible.
     * May still contain .. if the path is not absolute and contains
     * more ..s than directories.
     *
     * @return string The normalized path.
     */
    public static function normalizePath(
        string $file_path,
    ): string {
        if ( DIRECTORY_SEPARATOR !== '/' ) {
            $file_path = str_replace( DIRECTORY_SEPARATOR, '/', $file_path );
        }

        // Unix root or Windows UNC path
        if ( str_starts_with( $file_path, '/' ) ) {
            $prefix = DIRECTORY_SEPARATOR;
            // Windows drive
        } elseif ( preg_match( '/^[a-zA-Z]:/', $file_path ) ) {
            $prefix = mb_substr( $file_path, 0, 2 ) . DIRECTORY_SEPARATOR;
            // Not an absolute path
        } else {
            $prefix = '';
        }

        $acc = [];
        $parts = explode( '/', $file_path );
        foreach ( $parts as $part ) {
            if ( '' === $part ) {
                continue;
            }
            if ( '.' === $part ) {
                continue;
            }
            if ( '..' !== $part ) {
                $acc[] = $part;
                // .. pops the last directory off if there is one
            } elseif ( $acc !== [] ) {
                array_pop( $acc );
                // If there is nothing to pop and we aren't an absolute
                // path, keep .. in the normalized path.
            } elseif ( $prefix !== '' ) {
                $acc[] = $part;
            }
            // If there is nothing to pop and we are an absolute
            // path, discard the "..". We can't go any further up
            // than the root.
        }

        return $prefix . implode( DIRECTORY_SEPARATOR, $acc );
    }
}
