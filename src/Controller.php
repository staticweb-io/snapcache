<?php

namespace SnapCache;

class Controller {
    /**
     * Main controller
     *
     * @var Controller Instance.
     */
    protected static $plugin_instance;

    protected function __construct() {}

    /**
     * @return Controller Instance of self.
     */
    public static function getInstance(): Controller {
        if ( null === self::$plugin_instance ) {
            self::$plugin_instance = new self();
        }

        return self::$plugin_instance;
    }

    public static function init(): Controller {
        return self::getInstance();
    }
}
