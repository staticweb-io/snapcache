<?php declare(strict_types=1);

namespace SnapCache\Admin;

class Controller {
    public static function addMenuPage(): void {
        add_menu_page(
            'SnapCache',
            'SnapCache',
            'manage_options',
            'snapcache',
            SettingsMain::render( ... ),
            'dashicons-superhero'
        );
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
    }
}
