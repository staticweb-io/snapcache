<?php declare(strict_types=1);

namespace SnapCache;

use PHPUnit\Framework\TestCase;

final class MemcachedObjectCacheTest extends TestCase {

    use ITTrait;

    public function testGetSetAddReplace(): void
    {
        $this->evalEmbeddedTest( 'test-get-set-add-replace.php' );
    }

    /**
     * Test that cache values persist between requests
     */
    public function testPersistence(): void {
        $k = uniqid();
        $v = uniqid();

        $this->wpCli( [ 'cache', 'set', $k, $v ] );
        $lines = $this->wpCli( [ 'cache', 'get', $k ] );
        $this->assertContains( $v, $lines );

        $this->wpCli( [ 'cache', 'set', $k, $v, '', 600 ] );
        $lines = $this->wpCli( [ 'cache', 'get', $k, '' ] );
        $this->assertContains( $v, $lines );

        $this->wpCli( [ 'cache', 'set', $k, $v, 'default', 600 ] );
        $lines = $this->wpCli( [ 'cache', 'get', $k, 'default' ] );
        $this->assertContains( $v, $lines );

        $group = uniqid();
        $this->wpCli( [ 'cache', 'set', $k, $v, $group, 600 ] );
        $lines = $this->wpCli( [ 'cache', 'get', $k, $group ] );
        $this->assertContains( $v, $lines );
    }

    /**
     * Test that `wp cache type` shows correct output.
     */
    public function testType(): void {
        $lines = $this->wpCli( [ 'cache', 'type' ] );
        $this->assertContains( 'Memcache', $lines );
    }
}
