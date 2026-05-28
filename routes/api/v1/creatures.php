<?php

global $obRouter;

use App\Http\Response;
use App\Controller\Api;

$obRouter->get('/api/v1/creatures', [
    'middlewares' => ['api'],
    function($request) {
        return new Response(200, Api\Creatures::handle($request), 'application/json');
    }
]);
