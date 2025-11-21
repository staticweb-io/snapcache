<?php declare(strict_types=1);

namespace SnapCache\Admin;

use SnapCache\Memcached;
use SnapCache\Options;

class SettingsMain {
    /**
     * Register admin settings.
     */
    public static function register(): void {
        register_setting(
            'snapcache',
            'snapcache_object_cache',
            [
                'type' => 'string',
                'sanitize_callback' => Options::conformObjectCacheType( ... ),
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
        </div>
        <?php
    }

    public static function field_object_cache(): void {
        $val = Options::getObjectCacheType( true );
        $obj_cache = get_dropins()['object-cache.php'] ?? null;
        $obj_cache_is_ours = $obj_cache && ( ( $obj_cache['TextDomain'] ?? null ) === 'snapcache' );
        ?>
    <p>
        <label>
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
            }
            ?>
        </label>
    </p>
    <p>
        <label>
            <input type="radio" name="snapcache_object_cache" value="memcached"
            <?php checked( $val, 'memcached' ); ?> />
            Memcached
            <?php
                $mc = Memcached::getMemcached();
            if ( $mc instanceof \Memcached && $mc->getVersion() !== false ) {
                if ( $val === 'memcached' ) {
                    echo '<span style="font-weight: bold; color: green">(Active)</span>';
                } else {
                    echo '(Available)';
                }
            } elseif ( $val === 'memcached' ) {
                echo '<span style="font-weight: bold; color: red">(Connection failed)</span>';
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

        $val = esc_textarea( get_option( 'snapcache_memcached_servers', 'localhost:11211' ) );
        ?>
        <textarea name="snapcache_memcached_servers" rows="5" cols="50" class="large-text"
        <?php
        if ( ! $configurable ) {
            echo 'style="display: none"'; } ?>
        ><?php echo $val; ?></textarea>
        <p class="description">
        <?php if ( $configurable ) : ?>
            One host per line.
            Format: <code>host:port</code> or <code>host:port weight</code>.
            Default is <code>localhost:11211</code>.
            </p>
            <?php
        else :
            $mc = Memcached::getSnapCacheMemcached();
            $servers = $mc::getServersFromConfig();
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
                    <?php foreach ( $servers as $server ) : ?>
                    <tr>
                        <td style="padding-left: 10pt"><?php echo esc_html( $server[0] ); ?></td>
                        <td style="text-align: center; padding-left: 20pt">
                            <?php echo esc_html( $server[1] ?? 11211 ); ?></td>
                        <td style="text-align: center; padding-left: 20pt">
                            <?php echo esc_html( $server[2] ?? 0 ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
            To change the server list, either edit <code>SNAPCACHE_MEMCACHED_SERVERS</code>
            in <code>wp-config.php</code> or remove it and refresh this page.
            </p>
            <?php
        endif;
    }
}
