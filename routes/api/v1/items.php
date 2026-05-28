<?php
use App\Http\Response;
use App\Controller\Api;

$obRouter->get('/api/v1/items', [
    'middlewares' => ['api'],
    function($request) {
        return new Response(200, Api\Items::handle($request), 'application/json');
    }
]);
