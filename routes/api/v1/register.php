<?php

use App\Http\Response;
use App\Controller\Api;

$obRouter->post('/api/v1/register', [
    'middlewares' => [
        'api'
    ],
    function($request){
        $postVars = $request->getPostVars();
        $remoteIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        // Pega so o primeiro IP em caso de lista (X-Forwarded-For pode ter multiplos)
        $remoteIp = trim(explode(',', $remoteIp)[0]);
        return new Response(200, Api\Register::createAccount($postVars, $remoteIp), 'application/json');
    }
]);
