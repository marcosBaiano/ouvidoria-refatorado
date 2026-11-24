<?php
/**
 * Controlador de Denúncias
 * @author Sistema de Ouvidoria
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Validator.php';
require_once __DIR__ . '/../models/DenunciaModel.php';

class DenunciaController
{
    private DenunciaModel $model;
    private Response      $response;
    private string        $method;
    private string        $uri;

    public function __construct()
    {
        $this->model    = new DenunciaModel();
        $this->response = new Response();
        $this->method   = $_SERVER['REQUEST_METHOD'];
        $this->uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    }

    // ----------------------------------------------------------------
    // Router
    // ----------------------------------------------------------------

    public function route(): never
    {
        $uri    = $this->uri;
        $method = $this->method;

        // Estatísticas — deve ser verificado ANTES do padrão genérico /{id}
        if ($method === 'GET' && $uri === '/api/denuncias/stats') {
            $this->getStats();
        }

        // Busca
        if ($method === 'GET' && str_starts_with($uri, '/api/denuncias/buscar')) {
            $this->search();
        }

        // Por protocolo
        if ($method === 'GET' && preg_match('#^/api/denuncias/protocolo/([A-Z0-9]+)$#', $uri, $m)) {
            $this->getByProtocolo($m[1]);
        }

        // Listar
        if ($method === 'GET' && $uri === '/api/denuncias') {
            $this->listDenuncias();
        }

        // Criar
        if ($method === 'POST' && $uri === '/api/denuncias') {
            $this->create();
        }

        // Upload evidência
        if ($method === 'POST' && preg_match('#^/api/denuncias/([a-zA-Z0-9-]+)/evidencias$#', $uri, $m)) {
            $this->uploadEvidence($m[1]);
        }

        // Por ID — genérico
        if ($method === 'GET' && preg_match('#^/api/denuncias/([a-zA-Z0-9-]+)$#', $uri, $m)) {
            $this->getById($m[1]);
        }

        // Atualizar
        if ($method === 'PUT' && preg_match('#^/api/denuncias/([a-zA-Z0-9-]+)$#', $uri, $m)) {
            $this->update($m[1]);
        }

        $this->response->notFound()->send();
    }

    // ----------------------------------------------------------------
    // Ações
    // ----------------------------------------------------------------

    private function create(): never
    {
        try {
            $input = $this->parseJsonInput();

            $validator = (new Validator($input))
                ->required('tipo')
                ->required('descricao')
                ->required('local')
                ->enum('tipo', array_keys(TIPOS_DENUNCIA))
                ->enum('local', array_keys(LOCAIS_DENUNCIA))
                ->minLength('descricao', 20);

            if ($validator->hasErrors()) {
                $this->response->badRequest('Dados inválidos', $validator->getErrors())->send();
            }

            $id        = Helper::generateUUID();
            $protocolo = Helper::generateProtocolNumber();
            $anonimo   = Helper::shouldBeAnonymous($input['anonimato'] ?? true);

            $data = [
                'id'             => $id,
                'protocolo'      => $protocolo,
                'tipo'           => Helper::sanitize($input['tipo']),
                'descricao'      => Helper::sanitize($input['descricao']),
                'envolvidos'     => json_encode($input['envolvidos'] ?? [], JSON_UNESCAPED_UNICODE),
                'local'          => Helper::sanitize($input['local']),
                'data_periodo'   => Helper::sanitize($input['data_periodo'] ?? ''),
                'testemunhas'    => Helper::sanitize($input['testemunhas'] ?? ''),
                'comunicado'     => $input['comunicado'] ?? false,
                'quem_comunicou' => Helper::sanitize($input['quem_comunicou'] ?? ''),
                'anonimato'      => $anonimo,
                'contato'        => Helper::sanitize($input['contato'] ?? ''),
                'impacto'        => json_encode($input['impacto'] ?? [], JSON_UNESCAPED_UNICODE),
                'ip_origem'      => Helper::getClientIP($anonimo),
            ];

            if (!$this->model->create($data)) {
                Helper::log('Erro ao criar denúncia: ' . $this->model->getLastError(), 'error');
                $this->response->internalServerError('Erro ao registrar denúncia')->send();
            }

            Helper::log("Denúncia criada: {$protocolo}", 'info');

            $this->response->created([
                'id'        => $id,
                'protocolo' => $protocolo,
                'mensagem'  => 'Denúncia registrada com sucesso. Guarde este protocolo para acompanhamento.',
            ], 'Denúncia criada com sucesso')->send();

        } catch (Exception $e) {
            Helper::log('Exceção ao criar denúncia: ' . $e->getMessage(), 'error');
            $this->response->internalServerError('Erro ao processar denúncia', $e->getMessage())->send();
        }
    }

    private function listDenuncias(): never
    {
        try {
            $page  = max(1, (int) ($_GET['page']  ?? 1));
            $limit = min((int) ($_GET['limit'] ?? ITEMS_PER_PAGE), 100);

            $filters = array_filter([
                'tipo'        => $_GET['tipo']        ?? null,
                'status'      => $_GET['status']      ?? null,
                'local'       => $_GET['local']        ?? null,
                'data_inicio' => $_GET['data_inicio'] ?? null,
                'data_fim'    => $_GET['data_fim']    ?? null,
            ]);

            $denuncias = $this->model->list($page, $limit, $filters);
            $total     = $this->model->count($filters);

            $this->response->success([
                'dados'     => $denuncias,
                'paginacao' => [
                    'pagina_atual'    => $page,
                    'itens_por_pagina'=> $limit,
                    'total_itens'     => $total,
                    'total_paginas'   => (int) ceil($total / $limit),
                ],
            ], 'Denúncias listadas com sucesso')->send();

        } catch (Exception $e) {
            Helper::log('Erro ao listar denúncias: ' . $e->getMessage(), 'error');
            $this->response->internalServerError('Erro ao listar denúncias')->send();
        }
    }

    private function getById(string $id): never
    {
        try {
            $denuncia = $this->model->getById($id);

            if (!$denuncia) {
                $this->response->notFound('Denúncia não encontrada')->send();
            }

            $denuncia['evidencias']  = $this->model->getEvidencias($id);
            $denuncia['comentarios'] = $this->model->getComments($id);

            $this->response->success($denuncia)->send();

        } catch (Exception $e) {
            Helper::log('Erro ao obter denúncia: ' . $e->getMessage(), 'error');
            $this->response->internalServerError()->send();
        }
    }

    private function getByProtocolo(string $protocolo): never
    {
        try {
            $denuncia = $this->model->getByProtocolo($protocolo);

            if (!$denuncia) {
                $this->response->notFound('Denúncia não encontrada')->send();
            }

            $this->response->success($denuncia)->send();

        } catch (Exception $e) {
            Helper::log('Erro ao obter por protocolo: ' . $e->getMessage(), 'error');
            $this->response->internalServerError()->send();
        }
    }

    private function update(string $id): never
    {
        try {
            $input = $this->parseJsonInput();

            $denuncia = $this->model->getById($id);

            if (!$denuncia) {
                $this->response->notFound()->send();
            }

            // [CORRIGIDO] Valida status se enviado
            if (isset($input['status']) && !array_key_exists($input['status'], STATUS_DENUNCIA)) {
                $this->response->badRequest('Status inválido')->send();
            }

            $this->model->update($id, $input);

            Helper::log("Denúncia {$id} atualizada", 'info');

            $this->response->success(null, 'Denúncia atualizada com sucesso')->send();

        } catch (Exception $e) {
            Helper::log('Erro ao atualizar denúncia: ' . $e->getMessage(), 'error');
            $this->response->internalServerError()->send();
        }
    }

    private function uploadEvidence(string $idDenuncia): never
    {
        try {
            if (!isset($_FILES['arquivo'])) {
                $this->response->badRequest('Nenhum arquivo enviado')->send();
            }

            $file = $_FILES['arquivo'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $this->response->badRequest('Erro ao receber o arquivo')->send();
            }

            if (!Helper::isValidFileSize($file['size'])) {
                $this->response->badRequest('Arquivo excede o tamanho máximo permitido')->send();
            }

            if (!Helper::isValidFileExtension($file['name'])) {
                $this->response->badRequest('Tipo de arquivo não permitido')->send();
            }

            // [CORRIGIDO] Verifica MIME type real além da extensão
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);

            $allowedMimes = [
                'image/jpeg', 'image/png', 'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'video/mp4', 'video/x-msvideo',
            ];

            if (!in_array($mimeType, $allowedMimes, true)) {
                $this->response->badRequest('Tipo de arquivo não permitido (MIME inválido)')->send();
            }

            $safeFileName = Helper::generateSafeFileName($file['name']);
            $uploadPath   = UPLOAD_DIR . $safeFileName;

            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $this->response->internalServerError('Erro ao salvar arquivo')->send();
            }

            $this->model->addEvidence($idDenuncia, $safeFileName, $file['name'], $file['size']);

            Helper::log("Evidência enviada para denúncia {$idDenuncia}", 'info');

            $this->response->success([
                'arquivo' => $safeFileName,
                'tamanho' => Helper::formatFileSize($file['size']),
            ], 'Arquivo enviado com sucesso')->send();

        } catch (Exception $e) {
            Helper::log('Erro ao fazer upload: ' . $e->getMessage(), 'error');
            $this->response->internalServerError()->send();
        }
    }

    private function search(): never
    {
        try {
            $q    = trim($_GET['q'] ?? '');
            $page = max(1, (int) ($_GET['page'] ?? 1));

            if (mb_strlen($q, 'UTF-8') < 3) {
                $this->response->badRequest('Digite pelo menos 3 caracteres')->send();
            }

            $results = $this->model->search($q, $page);

            $this->response->success($results, 'Busca realizada com sucesso')->send();

        } catch (Exception $e) {
            $this->response->internalServerError()->send();
        }
    }

    private function getStats(): never
    {
        try {
            $stats = $this->model->getStatistics();
            $this->response->success($stats, 'Estatísticas obtidas com sucesso')->send();

        } catch (Exception $e) {
            $this->response->internalServerError()->send();
        }
    }

    // ----------------------------------------------------------------
    // Internos
    // ----------------------------------------------------------------

    /**
     * Lê e decodifica o corpo JSON da requisição.
     * [CORRIGIDO] Usa JSON_THROW_ON_ERROR para detectar payload malformado.
     */
    private function parseJsonInput(): array
    {
        $raw = file_get_contents('php://input');

        if (empty($raw)) {
            $this->response->badRequest('Corpo da requisição vazio')->send();
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->response->badRequest('JSON inválido: ' . $e->getMessage())->send();
        }

        if (!is_array($data)) {
            $this->response->badRequest('Payload deve ser um objeto JSON')->send();
        }

        return $data;
    }
}

// Executar controlador
$controller = new DenunciaController();
$controller->route();
