<?php declare(strict_types=1);

namespace SnapCache\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Controller {
    private static string $hook_suffix = '';

    public static function addMenuPage(): void {
        self::$hook_suffix = (string) add_menu_page(
            'SnapCache',
            'SnapCache',
            'manage_options',
            'snapcache',
            SettingsMain::render( ... ),
            'dashicons-superhero'
        );
    }

    public static function enqueueAssets( string $hook_suffix ): void {
        if ( $hook_suffix !== self::$hook_suffix ) {
            return;
        }

        SettingsMain::enqueueStyles();
    }

    /**
     * Add plugin elements to WordPress Admin UI
     */
    public static function addUIElements(): void {
        add_action(
            'admin_init',
            SettingsMain::register( ... ),
        );
        add_action(
            'admin_menu',
            self::addMenuPage( ... )
        );
        add_action(
            'admin_enqueue_scripts',
            self::enqueueAssets( ... )
        );
    }
}
