<?php
/**
 * Classe de Validação de Dados
 * @author Sistema de Ouvidoria
 */

class Validator
{
    private array $errors = [];
    private array $data   = [];

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    // ----------------------------------------------------------------
    // Regras
    // ----------------------------------------------------------------

    /** Campo obrigatório (string, array ou qualquer valor não-nulo/vazio). */
    public function required(string $field, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        // [CORRIGIDO] trim() em array causaria warning; verificação tipada
        $empty = $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && empty($value));

        if ($empty) {
            $this->errors[$field] = $message ?? "O campo '{$field}' é obrigatório";
        }

        return $this;
    }

    public function email(string $field, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = $message ?? "O campo '{$field}' deve ser um e-mail válido";
        }

        return $this;
    }

    public function minLength(string $field, int $length, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if (is_string($value) && mb_strlen($value, 'UTF-8') < $length) {
            $this->errors[$field] = $message ?? "O campo '{$field}' deve ter no mínimo {$length} caracteres";
        }

        return $this;
    }

    public function maxLength(string $field, int $length, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if (is_string($value) && mb_strlen($value, 'UTF-8') > $length) {
            $this->errors[$field] = $message ?? "O campo '{$field}' deve ter no máximo {$length} caracteres";
        }

        return $this;
    }

    /**
     * Valida tipo de dado.
     * [CORRIGIDO] Usa match em vez de switch para retornar diretamente.
     */
    public function type(string $field, string $type, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if ($value === null) {
            return $this;
        }

        $valid = match ($type) {
            'int', 'integer' => is_int($value) || ctype_digit((string) $value),
            'float', 'double' => is_numeric($value),
            'string'         => is_string($value),
            'bool', 'boolean' => is_bool($value) || in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true),
            'array'           => is_array($value),
            default           => false,
        };

        if (!$valid) {
            $this->errors[$field] = $message ?? "O campo '{$field}' deve ser do tipo {$type}";
        }

        return $this;
    }

    public function enum(string $field, array $values, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if ($value !== null && !in_array($value, $values, true)) {
            $this->errors[$field] = $message ?? "O valor do campo '{$field}' não é válido";
        }

        return $this;
    }

    public function between(string $field, float $min, float $max, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if ($value !== null) {
            $num = (float) $value;
            if ($num < $min || $num > $max) {
                $this->errors[$field] = $message ?? "O campo '{$field}' deve estar entre {$min} e {$max}";
            }
        }

        return $this;
    }

    public function regex(string $field, string $pattern, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if (is_string($value) && !preg_match($pattern, $value)) {
            $this->errors[$field] = $message ?? "O campo '{$field}' não corresponde ao padrão esperado";
        }

        return $this;
    }

    public function phone(string $field, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if ($value !== null) {
            $digits = preg_replace('/\D/', '', (string) $value);
            if (!preg_match('/^\d{10,11}$/', $digits)) {
                $this->errors[$field] = $message ?? "O campo '{$field}' deve conter um telefone válido";
            }
        }

        return $this;
    }

    public function cpf(string $field, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if ($value !== null) {
            $cpf = preg_replace('/\D/', '', (string) $value);
            if (!$this->isCPFValid($cpf)) {
                $this->errors[$field] = $message ?? "O campo '{$field}' deve conter um CPF válido";
            }
        }

        return $this;
    }

    public function cnpj(string $field, ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if ($value !== null) {
            $cnpj = preg_replace('/\D/', '', (string) $value);
            if (!$this->isCNPJValid($cnpj)) {
                $this->errors[$field] = $message ?? "O campo '{$field}' deve conter um CNPJ válido";
            }
        }

        return $this;
    }

    public function date(string $field, string $format = 'd/m/Y', ?string $message = null): static
    {
        $value = $this->data[$field] ?? null;

        if ($value !== null) {
            $d = \DateTime::createFromFormat($format, (string) $value);
            if (!$d || $d->format($format) !== $value) {
                $this->errors[$field] = $message ?? "O campo '{$field}' deve ser uma data válida ({$format})";
            }
        }

        return $this;
    }

    /** Valida arquivo de upload presente em $_FILES. */
    public function file(string $field, ?int $maxSize = null, ?array $allowedTypes = null): static
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            return $this;
        }

        if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $this->errors[$field] = 'Erro ao receber o arquivo enviado';
            return $this;
        }

        $file = $_FILES[$field];

        if ($maxSize !== null && $file['size'] > $maxSize) {
            $this->errors[$field] = 'O arquivo excede o tamanho máximo permitido';
            return $this;
        }

        if ($allowedTypes !== null) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedTypes, true)) {
                $this->errors[$field] = 'Tipo de arquivo não permitido';
            }
        }

        return $this;
    }

    // ----------------------------------------------------------------
    // Resultado
    // ----------------------------------------------------------------

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): ?string
    {
        return !empty($this->errors) ? reset($this->errors) : null;
    }

    // ----------------------------------------------------------------
    // Helpers internos
    // ----------------------------------------------------------------

    private function isCPFValid(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    private function isCNPJValid(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        for ($t = 0; $t < 2; $t++) {
            $sum = 0;
            $m   = $t === 0 ? 5 : 6;

            for ($i = 0; $i < 8 + $t; $i++) {
                $sum += (int) $cnpj[$i] * $m;
                $m    = $m === 2 ? 9 : $m - 1;
            }

            $digit = (10 * $sum) % 11;
            $digit = $digit >= 10 ? 0 : $digit;

            if ((int) $cnpj[8 + $t] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
