<?php

require_once "vendor/autoload.php";
require_once "config.php";

$app = new \Slim\Slim(array(
      "debug"             => true
    , "view"              => new \Slim\Views\Twig()
    , "templates.path"    => "./www/views"
));

# For cross-origin requests
$corsOptions = array(
    "origin" => "*",
    "exposeHeaders" => array("Content-Type", "X-Requested-With", "X-authentication", "X-client"),
    "allowMethods" => array('GET', 'POST', 'PUT', 'DELETE', 'OPTIONS')
);

$cors = new \CorsSlim\CorsSlim($corsOptions);

$app->add($cors);

$pdo = new PDO(DB_METHOD.DB_NAME, DB_USERNAME, DB_PASSWORD);
$db = new NotORM($pdo);

require_once "includes/email.php";