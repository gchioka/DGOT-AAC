<?php
/**
 * DGOT Login endpoint - OTClient HTTP login (v1500)
 * Delegates to canaryaac Login controller which validates Argon2id passwords.
 */
require __DIR__.'/includes/app.php';

use App\Http\Router;
use App\Http\Response;
use App\Controller\Api;

$obRouter = new Router(URL);

$obRouter->get('/login.php', [
    function($request) {
        return new Response(200, Api\Login::getLogin($request), 'application/json');
    }
]);

$obRouter->post('/login.php', [
    function($request) {
        return new Response(200, Api\Login::getLogin($request), 'application/json');
    }
]);

$obRouter->run()->sendResponse();
