<?php
/**
 * Classe de Resposta da API
 * @author Sistema de Ouvidoria
 */

class Response
{
    private int    $statusCode = 200;
    private mixed  $data       = null;
    private string $message    = '';
    private array  $errors     = [];

    public function __construct(int $statusCode = 200, mixed $data = null, string $message = '')
    {
        $this->statusCode = $statusCode;
        $this->data       = $data;
        $this->message    = $message;
    }

    // ----------------------------------------------------------------
    // Fluent setters
    // ----------------------------------------------------------------

    public function setStatusCode(int $code): static
    {
        $this->statusCode = $code;
        return $this;
    }

    public function setData(mixed $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function addError(string $error): static
    {
        $this->errors[] = $error;
        return $this;
    }

    public function setErrors(array $errors): static
    {
        $this->errors = $errors;
        return $this;
    }

    // ----------------------------------------------------------------
    // Respostas semânticas
    // ----------------------------------------------------------------

    public function success(mixed $data = null, string $message = 'Sucesso'): static
    {
        $this->statusCode = 200;
        $this->data       = $data;
        $this->message    = $message;
        return $this;
    }

    public function created(mixed $data = null, string $message = 'Recurso criado com sucesso'): static
    {
        $this->statusCode = 201;
        $this->data       = $data;
        $this->message    = $message;
        return $this;
    }

    public function badRequest(string $message = 'Requisição inválida', array $errors = []): static
    {
        $this->statusCode = 400;
        $this->message    = $message;
        $this->errors     = $errors;
        return $this;
    }

    public function unauthorized(string $message = 'Não autorizado'): static
    {
        $this->statusCode = 401;
        $this->message    = $message;
        return $this;
    }

    public function forbidden(string $message = 'Acesso proibido'): static
    {
        $this->statusCode = 403;
        $this->message    = $message;
        return $this;
    }

    public function notFound(string $message = 'Recurso não encontrado'): static
    {
        $this->statusCode = 404;
        $this->message    = $message;
        return $this;
    }

    public function internalServerError(string $message = 'Erro interno do servidor', ?string $detail = null): static
    {
        $this->statusCode = 500;
        $this->message    = $message;
        if ($detail !== null) {
            // [CORRIGIDO] Detalhes do erro só expostos em desenvolvimento
            $this->errors[] = APP_ENV === 'development' ? $detail : 'Erro ao processar a requisição';
        }
        return $this;
    }

    public function serviceUnavailable(string $message = 'Serviço indisponível'): static
    {
        $this->statusCode = 503;
        $this->message    = $message;
        return $this;
    }

    // ----------------------------------------------------------------
    // Serialização / envio
    // ----------------------------------------------------------------

    public function toArray(): array
    {
        $response = [
            'status'    => $this->statusCode,
            'success'   => $this->statusCode >= 200 && $this->statusCode < 300,
            'message'   => $this->message,
            'data'      => $this->data,
            'timestamp' => date('c'),
        ];

        if (!empty($this->errors)) {
            $response['errors'] = $this->errors;
        }

        return $response;
    }

    /**
     * Envia resposta JSON e encerra execução.
     * [CORRIGIDO] Usa JSON_THROW_ON_ERROR para detectar falhas de encoding.
     */
    public function send(): never
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json; charset=utf-8');

        try {
            echo json_encode(
                $this->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            echo json_encode(['status' => 500, 'success' => false, 'message' => 'Erro ao serializar resposta']);
        }

        exit;
    }
}
