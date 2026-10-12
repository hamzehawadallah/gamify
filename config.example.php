<?php
/*
 * Gamify configuration
 * --------------------
 * The easiest way: upload the app and open your site in a browser — the setup
 * wizard asks for these values and writes config.php for you.
 *
 * Manual way: copy this file to "config.php" (same folder) and fill it in.
 * On Hostinger you find the database values in hPanel → Databases → MySQL Databases.
 */
return [
    // Shown in the browser tab and the header.
    'app_name' => 'Gamify',

    'db' => [
        'host' => 'localhost',          // Hostinger shared hosting: keep "localhost"
        'port' => 3306,
        'name' => 'u123456789_gamify',  // database name
        'user' => 'u123456789_gamify',  // database user
        'pass' => 'your-database-password',
    ],

    // The admin login for creating activities and presenting results.
    // The password can be plain text or a password_hash() value.
    'admin' => [
        'username' => 'admin',
        'password' => 'choose-a-strong-password',
    ],

    // A long random string used to sign login cookies. Any random text works.
    'secret' => 'replace-me-with-a-long-random-string',

    // Optional. Leave empty to detect automatically, e.g. 'https://vote.example.com'
    'base_url' => '',

    // Show detailed errors. Keep false on a live site.
    'debug' => false,
];
