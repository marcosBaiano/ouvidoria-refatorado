<?php
/**
 * Funções Auxiliares
 * @author Sistema de Ouvidoria
 */

class Helper
{
    // ----------------------------------------------------------------
    // UUID / Protocolo
    // ----------------------------------------------------------------

    /**
     * Gera UUID v4 usando random_bytes (criptograficamente seguro).
     * [CORRIGIDO] mt_rand() não é seguro para geração de UUIDs.
     */
    public static function generateUUID(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // versão 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variante RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Gera número de protocolo único. */
    public static function generateProtocolNumber(): string
    {
        return 'OUVI' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    // ----------------------------------------------------------------
    // Sanitização
    // ----------------------------------------------------------------

    /**
     * Sanitiza entrada de dados.
     * [CORRIGIDO] Retorno tipado + suporte recursivo a arrays.
     */
    public static function sanitize(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize'], $data);
        }

        if (!is_string($data)) {
            return $data;
        }

        return htmlspecialchars(trim($data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ----------------------------------------------------------------
    // Validações simples
    // ----------------------------------------------------------------

    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    // ----------------------------------------------------------------
    // Criptografia
    // ----------------------------------------------------------------

    /**
     * Criptografa dados com AES-256-GCM (autenticado).
     * [CORRIGIDO] AES-256-CBC trocado por GCM — autenticação integrada,
     *             elimina necessidade de HMAC separado e é mais seguro.
     */
    public static function encrypt(string $data): string
    {
        $cipher = 'aes-256-gcm';
        $iv     = random_bytes(openssl_cipher_iv_length($cipher));
        $tag    = '';

        $encrypted = openssl_encrypt($data, $cipher, ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        return base64_encode($iv . $tag . $encrypted);
    }

    /** Descriptografa dados criptografados com encrypt(). */
    public static function decrypt(string $data): string|false
    {
        $cipher   = 'aes-256-gcm';
        $raw      = base64_decode($data);
        $ivLen    = openssl_cipher_iv_length($cipher);
        $iv       = substr($raw, 0, $ivLen);
        $tag      = substr($raw, $ivLen, 16);
        $payload  = substr($raw, $ivLen + 16);

        return openssl_decrypt($payload, $cipher, ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    }

    // ----------------------------------------------------------------
    // Senhas
    // ----------------------------------------------------------------

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    // ----------------------------------------------------------------
    // JWT
    // ----------------------------------------------------------------

    public static function generateJWT(array $payload): string
    {
        $header  = self::base64url_encode((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload['iat'] = time();
        $payload['exp'] = time() + JWT_EXPIRATION;
        $body    = self::base64url_encode((string) json_encode($payload));

        $sig = hash_hmac('sha256', "{$header}.{$body}", JWT_SECRET, true);

        return "{$header}.{$body}." . self::base64url_encode($sig);
    }

    /** Valida JWT e retorna o payload ou false. */
    public static function validateJWT(string $token): array|false
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        [$headerB64, $bodyB64, $sigB64] = $parts;

        // [CORRIGIDO] hash_equals() previne timing attacks
        $expectedSig = self::base64url_encode(
            hash_hmac('sha256', "{$headerB64}.{$bodyB64}", JWT_SECRET, true)
        );

        if (!hash_equals($expectedSig, $sigB64)) {
            return false;
        }

        $payload = json_decode(self::base64url_decode($bodyB64), true);

        if (!is_array($payload)) {
            return false;
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }

        return $payload;
    }

    public static function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64url_decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 4 - strlen($data) % 4));
    }

    // ----------------------------------------------------------------
    // Formatação
    // ----------------------------------------------------------------

    public static function formatDate(?string $date): string
    {
        if (!$date) {
            return '';
        }
        $ts = strtotime($date);
        return $ts !== false ? date(DATE_FORMAT, $ts) : $date;
    }

    public static function formatDateTime(?string $datetime): string
    {
        if (!$datetime) {
            return '';
        }
        $ts = strtotime($datetime);
        return $ts !== false ? date(DATETIME_FORMAT, $ts) : $datetime;
    }

    public static function formatCurrency(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    public static function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow   = $bytes > 0 ? (int) floor(log($bytes) / log(1024)) : 0;
        $pow   = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    // ----------------------------------------------------------------
    // Upload
    // ----------------------------------------------------------------

    public static function isValidFileExtension(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, UPLOAD_ALLOWED_TYPES, true);
    }

    public static function isValidFileSize(int $fileSize): bool
    {
        return $fileSize <= UPLOAD_MAX_SIZE;
    }

    /** Gera nome de arquivo seguro sem expor informações do original. */
    public static function generateSafeFileName(string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
    }

    // ----------------------------------------------------------------
    // Logging
    // ----------------------------------------------------------------

    public static function log(string $message, string $level = 'info'): void
    {
        $ts      = date(DATETIME_FORMAT);
        $level   = strtolower($level);
        $logFile = LOG_DIR . $level . '.log';

        file_put_contents($logFile, "[{$ts}] [{$level}] {$message}\n", FILE_APPEND | LOCK_EX);
    }

    // ----------------------------------------------------------------
    // Anonimato / IP
    // ----------------------------------------------------------------

    public static function shouldBeAnonymous(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * Retorna o IP do cliente.
     * [CORRIGIDO] Valida o IP via filter_var antes de retornar
     *             (HTTP_CLIENT_IP e HTTP_X_FORWARDED_FOR são facilmente forjados).
     */
    public static function getClientIP(bool $anonymous = false): string
    {
        if ($anonymous) {
            return 'ANÔNIMO';
        }

        $candidates = [
            $_SERVER['HTTP_CLIENT_IP']       ?? '',
            explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0],
            $_SERVER['REMOTE_ADDR']          ?? '',
        ];

        foreach ($candidates as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'DESCONHECIDO';
    }

    // ----------------------------------------------------------------
    // JSON
    // ----------------------------------------------------------------

    public static function toJson(mixed $data, bool $prettyPrint = false): string|false
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }
        try {
            return json_encode($data, $flags);
        } catch (\JsonException $e) {
            return false;
        }
    }

    public static function fromJson(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }
}
