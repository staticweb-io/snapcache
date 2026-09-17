<?php declare(strict_types=1);

namespace SnapCache\Admin;

use SnapCache\Memcached;
use SnapCache\MemcachedStats;
use SnapCache\Options;

class SettingsMain {
    private const STATS_CSS = '
        .snapcache-meter {
            -webkit-appearance: none;
            appearance: none;
            display: block;
            width: 100%;
            height: 6px;
            margin: 6px 0 4px;
            border: none;
        }
        .snapcache-meter::-webkit-meter-bar {
            background: #f0f0f1;
            border: none;
            border-radius: 3px;
        }
        .snapcache-meter::-webkit-meter-optimum-value,
        .snapcache-meter::-webkit-meter-suboptimum-value,
        .snapcache-meter::-webkit-meter-even-less-good-value {
            background: #2271b1;
            border-radius: 3px;
        }
        .snapcache-meter::-moz-meter-bar { background: #2271b1; }
    ';

    /**
     * Enqueue admin styles for the settings page.
     */
    public static function enqueueStyles(): void {
        wp_register_style( 'snapcache-admin', false, [], SNAPCACHE_VERSION );
        wp_enqueue_style( 'snapcache-admin' );
        wp_add_inline_style( 'snapcache-admin', self::STATS_CSS );
    }

    /**
     * Register admin settings.
     */
    public static function register(): void {
        register_setting(
            'snapcache',
            'snapcache_object_cache',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'disabled',
            ]
        );

        register_setting(
            'snapcache',
            'snapcache_memcached_servers',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default' => 'localhost:11211',
            ]
        );

        add_settings_section(
            'snapcache_settings_section',
            'Object Cache',
            null,
            'snapcache_main',
        );

        add_settings_field(
            'snapcache_object_cache',
            'Object Cache',
            self::field_object_cache( ... ),
            'snapcache_main',
            'snapcache_settings_section',
        );

        add_settings_field(
            'snapcache_memcached_servers',
            'Memcached Servers',
            self::field_memcached_servers( ... ),
            'snapcache_main',
            'snapcache_settings_section',
            [ 'class' => 'snapcache-memcached-field' ],
        );
    }

    /**
     * Render the admin page.
     */
    public static function render(): void {
        wp_cache_delete( 'snapcache_memcached_servers', 'options' );
        wp_cache_delete( 'snapcache_object_cache', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        ?>
        <div class="wrap">
            <h1>SnapCache Settings</h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'snapcache' );
                do_settings_sections( 'snapcache_main' );
                submit_button();
                ?>
            </form>

            <h2>Memcached Stats</h2>
            <?php self::renderStats(); ?>
        </div>
        <?php
    }

    public static function field_object_cache(): void {
        $val = Options::getObjectCacheType( true );
        $obj_cache = get_dropins()['object-cache.php'] ?? null;
        $obj_cache_is_ours = $obj_cache && ( ( $obj_cache['TextDomain'] ?? null ) === 'snapcache' );
        $obj_cache_path = WP_CONTENT_DIR . '/object-cache.php';
        ?>
    <p>
        <label
        <?php
        if ( $val === 'disabled' ) {
            echo ' style="font-weight: bold"';} ?>>
            <input type="radio" name="snapcache_object_cache" value="disabled"
            <?php checked( $val, 'disabled' ); ?> />
            Disabled
            <?php
            if ( ! $obj_cache ) {
                echo '(No object cache detected)';
            } elseif ( ! $obj_cache_is_ours ) {
                echo '(Object cache was installed by another plugin: ' .
                esc_html( $obj_cache['Name'] ?? 'Unknown' ) .
                ' ' . esc_html( $obj_cache['Version'] ?? '(unknown version)' ) . ')';
            } elseif ( $val === 'disabled' ) {
                echo '<span style="color: red">(Object cache installed)</span>';
            }
            ?>
        </label>
    </p>
    <p>
        <label
        <?php
        if ( $val === 'memcached' ) {
            echo ' style="font-weight: bold"';} ?>>
            <input type="radio" name="snapcache_object_cache" value="memcached"
            <?php checked( $val, 'memcached' ); ?> />
            Memcached
            <?php
                $mc = Memcached::getMemcached();
            if ( $mc instanceof \Memcached && $mc->getVersion() !== false ) {
                if ( $val !== 'memcached' ) {
                    echo '(Available)';
                } elseif ( $obj_cache_is_ours ) {
                    echo '<span style="color: green">(Active)</span>';
                } elseif ( $obj_cache ) {
                    echo '<span style="color: red">' .
                    '(Object cache was installed by another plugin: ' .
                    esc_html( $obj_cache['Name'] ?? 'Unknown' ) .
                    ' ' . esc_html( $obj_cache['Version'] ?? '(unknown version)' ) . ')' .
                    '</span>' .
                    ' Disable it in the other plugin or by removing' .
                    ' the <code>' .
                    esc_html( $obj_cache_path ) .
                    '</code> file.';
                } else {
                    echo '<span style="color: red">(Available but not installed.' .
                    ' Try disabling and re-enabling,' .
                    ' or run <code>wp snapcache memcached install</code>.)</span>';
                }
            } elseif ( $val === 'memcached' ) {
                if ( Memcached::extensionAvailable() ) {
                    echo '<span style="color: red">(Connection failed)</span>';
                } else {
                    echo '<span style="color: red">(Memcached extension not available)</span>';
                }
            } elseif ( ! Memcached::extensionAvailable() ) {
                echo '(Memcached extension not available)';
            } else {
                echo '(No server detected)';
            }
            ?>
        </label>
    </p>
        <?php
    }

    public static function field_memcached_servers(): void {
        $configurable = ! Options::isMemcachedServersInWpConfig();

        $val = get_option( 'snapcache_memcached_servers', 'localhost:11211' );
        ?>
        <textarea name="snapcache_memcached_servers" rows="5" cols="50" class="large-text"
        <?php
        if ( ! $configurable ) {
            echo 'style="display: none"'; } ?>
        ><?php echo esc_textarea( $val ); ?></textarea>
        <p class="description">
        <?php if ( $configurable ) : ?>
            One host per line.
            Format: <code>host:port</code> or <code>host:port weight</code>.
            Default is <code>localhost:11211</code>.
            </p>
            <?php
        endif;
        echo '</p>';
        if ( Memcached::extensionAvailable() ) {
            $mc = Memcached::getSnapCacheMemcached();
            $servers = $mc::getServersFromConfig();
        } else {
            $servers = [];
        }
        if ( $servers !== [] ) {
            if ( $configurable ) {
                echo '<p>Saved values</p>';
            }
            ?>
            <table class="widefat striped" style="width: auto; margin-top: 10px;">
                <thead>
                    <tr>
                        <th scope="col" style="padding-left: 20px; width: auto">Host</th>
                        <th scope="col" style="text-align: center; padding-left: 20px; width: auto">
                            Port</th>
                        <th scope="col"
                            style="text-align: center; padding: 20px; width: auto">
                            Weight</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ( $servers as $server ) : ?>
                            <tr>
                                <td style="padding-left: 10pt">
                                    <?php echo esc_html( $server[0] ); ?></td>
                                <td style="text-align: center; padding-left: 20pt">
                                    <?php echo esc_html( $server[1] ?? 11211 ); ?></td>
                                <td style="text-align: center; padding-left: 20pt">
                                    <?php echo esc_html( $server[2] ?? 0 ); ?></td>
                            </tr>
                            <?php
                            endforeach;
                ?>
                </tbody>
            </table>
            <?php
        } if ( ! $configurable ) : ?>
            <p>
            To change the server list, either edit <code>SNAPCACHE_MEMCACHED_SERVERS</code>
            in <code>wp-config.php</code> or remove it and refresh this page.
            </p>
                <?php
            endif;
    }

    private static function renderStats(): void {
        $data = MemcachedStats::get();

        if ( isset( $data['error'] ) ) {
            echo '<p>' . esc_html( $data['error'] ) . '</p>';
            return;
        }

        foreach ( $data['servers'] as $server_data ) {
            self::renderServerStats( $server_data );
        }
    }

    /**
     * @param array{
     *   server: string,
     *   version?: string,
     *   error?: string,
     *   cards?: array<int, array{
     *     label: string,
     *     value: string,
     *     sub: string,
     *     meter: array{value: float, max: float}|null,
     *   }>,
     *   raw?: array<string, mixed>,
     * } $server_data
     */
    private static function renderServerStats( array $server_data ): void {
        $heading = $server_data['server'];
        if ( isset( $server_data['version'] ) ) {
            $heading .= ' (v' . $server_data['version'] . ')';
        }
        echo '<h3>' . esc_html( $heading ) . '</h3>';

        if ( isset( $server_data['error'] ) ) {
            echo '<p style="color: #d63638;">' . esc_html( $server_data['error'] ) . '</p>';
            return;
        }
        ?>
        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin: 12px 0 24px;">
            <?php foreach ( $server_data['cards'] as $card ) : ?>
                <?php self::renderStatCard( $card ); ?>
            <?php endforeach; ?>
        </div>
        <details>
            <summary style="cursor: pointer; margin-bottom: 8px; user-select: none;">
                All stats
            </summary>
            <table class="widefat striped" style="width: auto; margin-top: 8px;">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $server_data['raw'] as $name => $value ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $name ); ?></td>
                        <td><?php echo esc_html( (string) $value ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php
    }

    /**
     * @param array{
     *   label: string,
     *   value: string,
     *   sub: string,
     *   meter: array{value: float, max: float}|null,
     * } $card
     */
    private static function renderStatCard( array $card ): void {
        ?>
        <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;
            padding: 14px 18px; min-width: 140px;">
            <div style="font-size: 11px; color: #646970; text-transform: uppercase;
                letter-spacing: .5px; margin-bottom: 4px;">
                <?php echo esc_html( $card['label'] ); ?>
            </div>
            <div style="font-size: 22px; font-weight: 600; line-height: 1.2;">
                <?php echo esc_html( $card['value'] ); ?>
            </div>
            <?php if ( $card['meter'] !== null ) : ?>
            <meter class="snapcache-meter"
                value="<?php echo esc_attr( (string) $card['meter']['value'] ); ?>"
                min="0"
                max="<?php echo esc_attr( (string) $card['meter']['max'] ); ?>">
            </meter>
            <?php endif; ?>
            <div style="font-size: 12px; color: #646970;">
                <?php echo esc_html( $card['sub'] ); ?>
            </div>
        </div>
        <?php
    }
}
