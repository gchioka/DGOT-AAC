<?php
namespace App\Http\Middleware;

use App\Http\Request;
use Closure;
use App\Http\Response;

class Api {

    /** Origens permitidas para CORS */
    private static function getAllowedOrigin(): ?string
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (empty($origin)) {
            return null;
        }

        // Origens explicitamente permitidas
        $allowed = [
            'https://dgot.com.br',
            'https://www.dgot.com.br',
            'http://localhost:3000',
            'http://localhost:5173',
        ];

        if (in_array($origin, $allowed, true)) {
            return $origin;
        }

        // Subdomínios do Lovable (preview e produção)
        if (preg_match('/^https:\/\/[a-z0-9-]+\.lovable\.app$/', $origin)) {
            return $origin;
        }

        // Bloqueia qualquer outra origem
        return null;
    }

    public function handle($request, $next)
    {
        $origin = self::getAllowedOrigin();

        if ($origin) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');

        // Responde preflight imediatamente
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $request->getRouter()->setContentType('application/json');
        return $next($request);
    }
}
