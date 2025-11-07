<?php declare(strict_types=1);

namespace SnapCache;

use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {

    use ITTrait;

    public function testUninstall(): void
    {
        $this->wpCli(
            [
                'plugin',
                'uninstall',
                'snapcache',
                '--deactivate',
                '--skip-delete',
            ]
        );
        // Reactivate so that when running tests against the dev server,
        // I don't have to manually activate it again.
        $this->wpCli( [ 'plugin', 'activate', 'snapcache' ] );
    }
}
