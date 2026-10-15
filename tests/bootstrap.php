<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// var/ is not in git: a fresh clone (CI) has no place for the test database.
@mkdir(dirname(__DIR__).'/var/data', 0o775, true);
