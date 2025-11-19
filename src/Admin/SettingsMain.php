<?php declare(strict_types=1);

namespace SnapCache\Admin;

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
        $val = Options::getObjectCacheType();
        ?>
    <p>
        <label>
            <input type="radio" name="snapcache_object_cache" value="disabled"
            <?php checked( $val, 'disabled' ); ?> />
            Disabled
        </label>
    </p>
    <p>
        <label>
            <input type="radio" name="snapcache_object_cache" value="memcached"
            <?php checked( $val, 'memcached' ); ?> />
            Memcached
        </label>
    </p>
        <?php
    }

    public static function field_memcached_servers(): void {
        $val = esc_textarea( get_option( 'snapcache_memcached_servers', 'localhost:11211' ) );
        ?>
        <textarea name="snapcache_memcached_servers" rows="5" cols="50" class="large-text"
        ><?php echo $val; ?></textarea>
        <p class="description">
            One host per line. Format: <code>host:port</code>.
            Default is <code>localhost:11211</code>.
        </p>
        <?php
    }
}
