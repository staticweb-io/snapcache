<?php declare(strict_types=1);

namespace SnapCache;

use PHPUnit\Framework\TestCase;

final class MemcachedStatsTest extends TestCase {

    use ITTrait;

    /**
     * Tests MemcachedStats::buildServerData() using synthetic stats arrays.
     */
    public function testBuildServerData(): void {
        $this->evalEmbeddedTest( 'test-memcached-stats-build-server-data.php' );
    }

    /**
     * Tests MemcachedStats::get() against the real memcached
     * server used by the dev environment.
     */
    public function testGetAgainstLiveServer(): void {
        $this->evalEmbeddedTest( 'test-memcached-stats-get-live.php' );
    }

    /**
     * Tests the `debug _internal memcached_stats` CLI command end-to-end
     * against the real memcached server.
     */
    public function testCliInternalStatsCards(): void {
        $lines = $this->pluginCli(
            [ 'debug', '_internal', 'memcached_stats', '--format=json' ]
        )['final_line'];

        $data = json_decode( (string) $lines, true );

        $this->assertIsArray( $data );
        $this->assertArrayHasKey( 'servers', $data );
        $this->assertCount( 1, $data['servers'] );

        $server = $data['servers'][0];
        $this->assertArrayNotHasKey( 'error', $server );
        $this->assertSame(
            [ 'Hit Rate', 'Memory', 'Uptime' ],
            array_column( $server['cards'], 'label' ),
        );
    }
}
