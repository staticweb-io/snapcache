<?php declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export

namespace SnapCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the memcached+APCu drop-in in PHP-FPM, whose APCu cache WP-CLI
 * can't reach, with WP-CLI and other processes writing to memcached.
 */
final class ApcuCliFpmTest extends TestCase {

    use FpmTrait;
    use ITTrait {
        setUp as itSetUp;
    }

    protected function setUp(): void {
        $this->itSetUp();
        $this->installFpmEndpoint();
        $this->wpCli( [ 'option', 'delete', 'snapcache_use_apcu' ] );
        $this->pluginCli( [ 'memcached', 'install' ] );
    }

    protected function tearDown(): void {
        $this->removeFpmEndpoint();
    }

    private function dropIn(): string {
        return (string) file_get_contents(
            ITEnv::getWordPressDir() . '/wp-content/object-cache.php',
        );
    }

    public function testOptionChoosesTheDropIn(): void {
        $this->assertStringContainsString( 'APCu', $this->dropIn(), 'APCu is the default' );

        $this->wpCli( [ 'option', 'update', 'snapcache_use_apcu', '0' ] );
        $this->assertStringNotContainsString( 'APCu', $this->dropIn() );

        $this->wpCli( [ 'option', 'update', 'snapcache_use_apcu', '1' ] );
        $this->assertStringContainsString( 'APCu', $this->dropIn() );
    }

    public function testInstallWithApcuFlag(): void {
        $this->wpCli( [ 'option', 'update', 'snapcache_use_apcu', '0' ] );

        $this->pluginCli( [ 'memcached', 'install', '--apcu' ] );

        $this->assertStringContainsString( 'APCu', $this->dropIn() );
        $this->wpCli( [ 'eval', 'do_action( "admin_init" );' ] );
        $this->assertStringContainsString( 'APCu', $this->dropIn(), 'The choice sticks' );
    }

    public function testAdminReplacesADropInThatDoesNotMatch(): void {
        copy(
            ITEnv::getPluginsDir() . '/snapcache/src/drop-in/object-cache-memcached.php',
            ITEnv::getWordPressDir() . '/wp-content/object-cache.php',
        );

        $this->wpCli( [ 'eval', 'do_action( "admin_init" );' ] );

        $this->assertStringContainsString( 'APCu', $this->dropIn() );
    }

    public function testFpmUsesApcuAndCliDoesNot(): void {
        $this->assertSame( [ true ], $this->fpm( [ [ 'apcu_active' ] ] ) );
        $this->assertSame( [ false ], $this->cli( [ [ 'apcu_active' ] ] ) );
    }

    public function testCliWithApcuEnabledDoesNotUseIt(): void {
        $k = uniqid();
        $this->cli( [ [ 'set', $k, 'old' ] ] );
        $this->fpm( [ [ 'get_uncached', $k ] ] );

        $results = $this->php(
            PHP_BINARY . ' -d apc.enable_cli=1',
            [ [ 'apcu_active' ], [ 'set', $k, 'new' ] ],
        );

        $this->assertSame( [ false, true ], $results );
        $this->assertSame( [ 'new' ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
    }

    public function testReadsAreServedFromApcu(): void {
        $k = uniqid();
        $this->cli( [ [ 'set', $k, 'v' ] ] );

        $this->assertSame( [ 'v' ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
        $this->assertSame( [ [ 'v' ] ], $this->fpm( [ [ 'apcu_entry', $k ] ] ) );

        $this->fpm( [ [ 'mc_delete', $k ] ] );
        $this->assertSame( [ 'v' ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
        $this->assertSame( [ [ $k => 'v' ] ], $this->fpm( [ [ 'get_multiple', [ $k ] ] ] ) );
    }

    public function testForcedReadsSkipApcu(): void {
        $k = uniqid();
        $this->cli( [ [ 'set', $k, 'v' ] ] );
        $this->fpm( [ [ 'get_uncached', $k ] ] );
        $this->fpm( [ [ 'mc_delete', $k ] ] );

        $this->assertSame( [ false ], $this->fpm( [ [ 'get', $k, '', true ] ] ) );
    }

    /**
     * @return array<string, array{mixed, callable(string): list<mixed>, mixed}>
     *   Initial value (null for none), the write, and the value expected after.
     */
    public static function writes(): array {
        return [
            'add' => [ null, fn( $k ) => [ 'add', $k, 'new' ], 'new' ],
            'add_multiple' => [
                null,
                fn( $k ) => [ 'add_multiple', [ $k => 'new' ] ],
                'new',
            ],
            'decr' => [ 5, fn( $k ) => [ 'decr', $k, 2 ], 3 ],
            'delete' => [ 'old', fn( $k ) => [ 'delete', $k ], false ],
            'delete_multiple' => [
                'old',
                fn( $k ) => [ 'delete_multiple', [ $k ] ],
                false,
            ],
            'flush' => [ 'old', fn() => [ 'flush' ], false ],
            'incr' => [ 5, fn( $k ) => [ 'incr', $k, 2 ], 7 ],
            'replace' => [ 'old', fn( $k ) => [ 'replace', $k, 'new' ], 'new' ],
            'set' => [ 'old', fn( $k ) => [ 'set', $k, 'new' ], 'new' ],
            'set_multiple' => [
                'old',
                fn( $k ) => [ 'set_multiple', [ $k => 'new' ] ],
                'new',
            ],
        ];
    }

    /**
     * Fills APCu with the initial value or a miss, and returns the key.
     */
    private function fillApcu( mixed $initial ): string {
        $k = uniqid();
        if ( $initial !== null ) {
            $this->cli( [ [ 'set', $k, $initial ] ] );
        }
        $this->assertSame( [ $initial ?? false ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
        $this->assertSame(
            [ $initial === null ? [] : [ $initial ] ],
            $this->fpm( [ [ 'apcu_entry', $k ] ] ),
            'APCu has the initial state',
        );
        return $k;
    }

    #[DataProvider( 'writes' )]
    public function testCliWriteReachesFpm(
        mixed $initial,
        callable $write,
        mixed $expected,
    ): void {
        $k = $this->fillApcu( $initial );

        $this->cli( [ $write( $k ) ] );

        $this->assertSame( [ $expected ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
        $this->assertSame(
            [ [ $k => $expected ] ],
            $this->fpm( [ [ 'get_multiple', [ $k ] ] ] ),
        );
    }

    #[DataProvider( 'writes' )]
    public function testFpmWriteReachesFpmAndCli(
        mixed $initial,
        callable $write,
        mixed $expected,
    ): void {
        $k = $this->fillApcu( $initial );

        $this->fpm( [ $write( $k ) ] );

        $this->assertSame( [ $expected ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
        $this->assertSame( [ $expected ], $this->cli( [ [ 'get', $k ] ] ) );
    }

    public function testDeleteOfItemGoneFromMemcachedRemovesItFromApcu(): void {
        $k = $this->fillApcu( 'old' );
        $this->fpm( [ [ 'mc_delete', $k ] ] );

        $this->assertSame( [ false ], $this->fpm( [ [ 'delete', $k ] ] ) );

        $this->assertSame( [ false ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
    }

    public function testFailedAddKeepsAValueInApcu(): void {
        $k = $this->fillApcu( 'old' );

        $this->assertSame( [ false ], $this->fpm( [ [ 'add', $k, 'new' ] ] ) );

        $this->assertSame( [ [ 'old' ] ], $this->fpm( [ [ 'apcu_entry', $k ] ] ) );
    }

    public function testLostGenerationHidesApcu(): void {
        $k = $this->fillApcu( 'old' );
        $this->fpm( [ [ 'mc_delete', $k ], [ 'mc_delete_generation' ] ] );

        $this->assertSame( [ false ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
        $this->assertSame( [ true ], $this->fpm( [ [ 'apcu_active' ] ] ) );
    }

    public function testFlushOutsideTheCacheHidesApcu(): void {
        $k = $this->fillApcu( 'old' );

        $this->fpm( [ [ 'eval', 'global $wp_object_cache; $wp_object_cache->mc->flush();' ] ] );

        $this->assertSame( [ false ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
    }

    public function testItemsExpireInFpm(): void {
        $k = uniqid();
        $this->fpm( [ [ 'set', $k, 'v', '', 1 ] ] );
        $this->assertSame( [ 'v' ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );

        sleep( 3 );

        $this->assertSame( [ false ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
    }

    /**
     * The tests named testRace run each step of a race in a fixed order. The
     * tests under load below use real concurrency, but rarely hit such a
     * narrow window, so only these show that the races are handled.
     */
    public function testRaceReaderFillsAfterAWrite(): void {
        $k = uniqid();
        $this->cli( [ [ 'set', $k, 'old' ] ] );

        $this->fpm( [ [ 'race_fill_after_write', $k, 'new' ] ] );

        $this->assertSame( [ 'new' ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
    }

    public function testRaceWritersUpdateApcuOutOfOrder(): void {
        $k = uniqid();

        $this->fpm( [ [ 'race_two_writers', $k, 'first', 'second' ] ] );

        $this->assertSame( [ 'second' ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
    }

    public function testRaceReaderFillsDuringACliWrite(): void {
        $k = uniqid();
        $this->cli( [ [ 'set', $k, 'old' ] ] );

        $this->cli( [ [ 'race_fill_during_write', $k, 'new', $this->endpointUrl() ] ] );

        $this->assertSame( [ 'new' ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
    }

    /**
     * Every write's result must be readable in the next FPM request, while
     * other FPM workers are filling APCu.
     */
    public function testReadAfterCliWriteUnderLoad(): void {
        $k = uniqid();
        $stop_readers = $this->startReaders( $k );
        $url = var_export( $this->endpointUrl(), true );
        $key = var_export( $k, true );

        try {
            $this->wpCli(
                [
                    'eval',
                    <<<PHP
                    for ( \$i = 1; \$i <= 300; \$i++ ) {
                        wp_cache_set( {$key}, \$i );
                        \$body = json_encode( [ 'ops' => [ [ 'get_uncached', {$key} ] ] ] );
                        \$context = stream_context_create(
                            [ 'http' => [ 'content' => \$body, 'method' => 'POST' ] ]
                        );
                        \$got = json_decode( file_get_contents( {$url}, false, \$context ), true );
                        if ( \$got !== [ \$i ] ) {
                            WP_CLI::error( "Wrote \$i, read " . json_encode( \$got ) );
                        }
                    }
                    PHP,
                ]
            );
        } finally {
            $stop_readers();
        }
    }

    /**
     * Concurrent writers and readers in FPM. After they finish, a read must
     * see what memcached has, both right away and after the entry is filled.
     */
    public function testConcurrentFpmWritesAndReads(): void {
        $k = uniqid();
        $multi = curl_multi_init();

        for ( $round = 0; $round < 40; $round++ ) {
            $handles = [];
            foreach ( [ "a{$round}", "b{$round}" ] as $value ) {
                $handles[] = $this->curlHandle( [ [ 'set', $k, $value ] ] );
            }
            for ( $i = 0; $i < 6; $i++ ) {
                $handles[] = $this->curlHandle( [ [ 'get_uncached', $k ] ] );
            }
            foreach ( $handles as $handle ) {
                curl_multi_add_handle( $multi, $handle );
            }
            do {
                curl_multi_exec( $multi, $running );
                curl_multi_select( $multi, 1.0 );
            } while ( $running > 0 );
            foreach ( $handles as $handle ) {
                curl_multi_remove_handle( $multi, $handle );
            }

            [ $read, $forced ] = $this->fpm( [ [ 'get_uncached', $k ], [ 'get', $k, '', true ] ] );
            $this->assertSame( $forced, $read, "Round {$round}" );
        }
        curl_multi_close( $multi );

        // The tombstones have to expire before the entry can be filled.
        sleep( 11 );
        [ $forced ] = $this->fpm( [ [ 'get', $k, '', true ] ] );
        $this->assertSame( [ $forced ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
        $this->assertSame( [ [ $forced ] ], $this->fpm( [ [ 'apcu_entry', $k ] ] ) );
    }

    public function testUnreachableMemcachedWithApcu(): void {
        $id = uniqid( 'unreachable' );

        $this->assertSame(
            [
                [
                    'delete' => false,
                    'flush' => false,
                    'get' => false,
                    'set' => false,
                ],
            ],
            array_map(
                function ( array $r ): array {
                    ksort( $r );
                    return $r;
                },
                $this->fpm( [ [ 'unreachable', $id ] ] ),
            ),
        );
    }

    /**
     * Nix's php sets PHP_INI_SCAN_DIR for its children, which would
     * load the extensions of the php running the tests.
     */
    private static function withoutInheritedIni( string $php ): string {
        return 'env -u PHP_INI_SCAN_DIR ' . $php;
    }

    /**
     * Removing APCu must fall back to memcached right away, and its writes
     * must still reach the FPM workers that have APCu.
     */
    public function testApcuExtensionRemoved(): void {
        $php = getenv( 'PHP_WITHOUT_APCU' );
        if ( ! $php ) {
            $this->markTestSkipped( 'PHP_WITHOUT_APCU is not set' );
        }

        $k = $this->fillApcu( 'old' );

        $this->assertSame(
            [ false, true, 'new', 'new' ],
            $this->php(
                self::withoutInheritedIni( $php ),
                [
                    [ 'eval', 'return extension_loaded( "apcu" );' ],
                    [ 'set', $k, 'new' ],
                    [ 'get', $k ],
                    [ 'get_uncached', $k ],
                ],
            ),
        );
        $this->assertSame( [ 'new' ], $this->fpm( [ [ 'get_uncached', $k ] ] ) );
    }

    /**
     * Removing memcached leaves WordPress's per-request cache, and the
     * plugin's admin and settings code must still run.
     */
    public function testMemcachedExtensionRemoved(): void {
        $php = getenv( 'PHP_WITHOUT_MEMCACHED' );
        if ( ! $php ) {
            $this->markTestSkipped( 'PHP_WITHOUT_MEMCACHED is not set' );
        }

        $k = uniqid();
        $this->assertSame(
            [ false, false, true, 'v', null, null ],
            $this->php(
                'SNAPCACHE_TEST_ADMIN=1 ' . self::withoutInheritedIni( $php ),
                [
                    [ 'eval', 'return extension_loaded( "memcached" );' ],
                    [ 'eval', 'return wp_using_ext_object_cache();' ],
                    [ 'set', $k, 'v' ],
                    [ 'get', $k ],
                    [
                        'eval',
                        'require_once ABSPATH . "wp-admin/includes/admin.php"; '
                        . 'do_action( "admin_init" );',
                    ],
                    [
                        'eval',
                        'ob_start(); '
                        . '\SnapCache\Admin\SettingsMain::field_object_cache(); '
                        . '\SnapCache\Admin\SettingsMain::field_memcached_servers(); '
                        . '\SnapCache\Admin\SettingsMain::field_use_apcu(); '
                        . 'ob_end_clean();',
                    ],
                ],
            ),
        );
        $this->assertStringContainsString( 'APCu', $this->dropIn(), 'The drop-in stays in place' );
    }
}
