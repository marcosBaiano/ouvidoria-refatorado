<?php
/**
 * Ponto de entrada da API
 * @author Sistema de Ouvidoria
 */

// ----------------------------------------------------------------
// Headers de segurança e CORS
// [CORRIGIDO] Movidos de config.php para cá — só fazem sentido em contexto HTTP
// ----------------------------------------------------------------

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Headers de segurança
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ----------------------------------------------------------------
// Bootstrap
// ----------------------------------------------------------------

require_once __DIR__ . '/../config/config.php';

// Autoloader simples
spl_autoload_register(static function (string $class): void {
    $dirs = [
        __DIR__ . '/../core/',
        __DIR__ . '/../models/',
        __DIR__ . '/../controllers/',
    ];

    foreach ($dirs as $dir) {
        $file = $dir . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// ----------------------------------------------------------------
// Roteamento
// ----------------------------------------------------------------

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($uri, '/api/denuncias')) {
    // [CORRIGIDO] Controlador já é carregado via autoloader — não precisa de require manual
    $controller = new DenunciaController();
    $controller->route();
}

if ($uri === '/api' || $uri === '/') {
    $response = new Response(200, [
        'nome'      => APP_NAME,
        'versao'    => APP_VERSION,
        'endpoints' => [
            'POST /api/denuncias'                      => 'Criar nova denúncia',
            'GET  /api/denuncias'                      => 'Listar denúncias',
            'GET  /api/denuncias/{id}'                 => 'Obter denúncia por ID',
            'GET  /api/denuncias/protocolo/{numero}'   => 'Obter denúncia por protocolo',
            'PUT  /api/denuncias/{id}'                 => 'Atualizar denúncia',
            'POST /api/denuncias/{id}/evidencias'      => 'Upload de evidência',
            'GET  /api/denuncias/buscar?q={termo}'     => 'Buscar denúncias',
            'GET  /api/denuncias/stats'                => 'Obter estatísticas',
        ],
    ]);
    $response->send();
}

// Fallback 404
$response = new Response(404, null, 'Endpoint não encontrado');
$response->send();
