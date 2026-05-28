<?php

use App\Http\Response;
use App\Controller\Api;

$obRouter->get('/api/v1/server/status', [
    'middlewares' => [
        'api'
    ],
    function($request){
        return new Response(200, Api\Status::getServerStatus(), 'application/json');
    }
]);
