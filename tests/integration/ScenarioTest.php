<?php declare(strict_types=1);

namespace SnapCache;

use PHPUnit\Framework\TestCase;

final class ScenarioTest extends TestCase {

    use FpmTrait;
    use ITTrait;

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function scenarios(): array {
        return ScenarioRunner::loadAll( __DIR__ . '/../scenarios' );
    }

    /**
     * @param array<mixed> $scenario
     * @dataProvider scenarios
     */
    public function testScenario( array $scenario ): void {
        $this->wpCli( [ 'eval', ScenarioRunner::buildPhp( $scenario ) ] );
    }

    /**
     * Scenarios run against the memcached-only drop-in.
     * The other tests run against the default, which has APCu.
     */
    public function testScenariosWithoutApcu(): void {
        $this->wpCli( [ 'option', 'update', 'snapcache_use_apcu', '0' ] );
        try {
            foreach ( self::scenarios() as [ $scenario ] ) {
                $this->wpCli( [ 'eval', ScenarioRunner::buildPhp( $scenario ) ] );
            }
        } finally {
            $this->wpCli( [ 'option', 'delete', 'snapcache_use_apcu' ] );
            $this->pluginCli( [ 'memcached', 'install' ] );
        }
    }

    /**
     * @param array<mixed> $scenario
     * @dataProvider scenarios
     */
    public function testScenarioInFpm( array $scenario ): void {
        $this->installFpmEndpoint();
        try {
            $this->fpm( [ [ 'eval', ScenarioRunner::buildPhp( $scenario ) ] ] );
        } finally {
            $this->removeFpmEndpoint();
        }
    }

    /**
     * Each step in its own request, so that reads come from APCu or memcached.
     *
     * @param array<mixed> $scenario
     * @dataProvider scenarios
     */
    public function testScenarioInFpmPerRequest( array $scenario ): void {
        $steps = ScenarioRunner::buildPhpPerStep( $scenario );
        if ( $steps === null ) {
            $this->markTestSkipped( 'Depends on state that only lasts for a request' );
        }
        $this->installFpmEndpoint();
        try {
            foreach ( $steps as $php ) {
                $this->fpm( [ [ 'eval', $php ] ] );
            }
        } finally {
            $this->removeFpmEndpoint();
        }
    }
}
