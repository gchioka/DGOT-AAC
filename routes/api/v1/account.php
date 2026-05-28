<?php

global $obRouter;

use App\Http\Response;
use App\Controller\Api;

$obRouter->post('/api/v1/account', [
    'middlewares' => ['api'],
    function($request) {
        return new Response(200, Api\Account::handle($request), 'application/json');
    }
]);
