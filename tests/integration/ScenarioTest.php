<?php declare(strict_types=1);

namespace SnapCache;

use PHPUnit\Framework\TestCase;

final class ScenarioTest extends TestCase {

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
}
