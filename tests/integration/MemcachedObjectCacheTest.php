<?php declare(strict_types=1);

namespace SnapCache;

use PHPUnit\Framework\TestCase;

final class MemcachedObjectCacheTest extends TestCase {

    use ITTrait;

    public function testGetSetAddReplace(): void
    {
        $this->evalEmbeddedTest( 'test-get-set-add-replace.php' );
    }
}
