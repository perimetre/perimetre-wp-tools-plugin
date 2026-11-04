<?php

/**
 * Minimal stand-ins for the WordPress classes the plugin type-hints or
 * instantiates. Only what the code under test touches; behaviour is faked with
 * Brain Monkey, not here.
 *
 * phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
 * phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
 * phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
 */

declare(strict_types=1);

if (! class_exists('WP_User')) {
    class WP_User
    {
        public function __construct(
            public int $ID = 0,
            public string $user_login = '',
            public string $user_email = ''
        ) {
        }
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '')
        {
        }
    }
}
