<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH',  BASE_PATH . '/app');
define('START_TIME', microtime(true));

require BASE_PATH . '/config/bootstrap.php';


use App\Routes\Router;

$router = new Router();
require APP_PATH . '/routes/api.php';
require APP_PATH . '/routes/web.php';

$router->dispatch();