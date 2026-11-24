<?php
/**
 * Configurações da Aplicação de Ouvidoria Web
 * @author Sistema de Ouvidoria
 * @version 1.0
 */

// ============================================================
// CONFIGURAÇÕES GERAIS
// ============================================================

define('APP_NAME', 'Sistema de Ouvidoria Web');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost:8000');
define('APP_ENV', getenv('APP_ENV') ?: 'development'); // [CORRIGIDO] Leitura via env var em vez de hardcode

// ============================================================
// CONFIGURAÇÕES DE BANCO DE DADOS
// ============================================================

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'ouvidoria_db');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// CONFIGURAÇÕES DE SEGURANÇA
// ============================================================

// [CORRIGIDO] Segredos nunca hardcoded — lidos de variáveis de ambiente
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'CHANGE_ME_IN_PRODUCTION');
define('JWT_EXPIRATION', 3600);
define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: 'CHANGE_ME_32_BYTES_MIN_12345678');
define('SESSION_TIMEOUT', 1800);

// ============================================================
// CONFIGURAÇÕES DE ARMAZENAMENTO DE ARQUIVOS
// ============================================================

define('UPLOAD_DIR', __DIR__ . '/../uploads/evidencias/');
define('UPLOAD_MAX_SIZE', 52428800); // 50 MB
define('UPLOAD_ALLOWED_TYPES', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'mp4', 'avi']);

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// ============================================================
// CONFIGURAÇÕES DE LOGGING
// ============================================================

define('LOG_DIR', __DIR__ . '/../logs/');
define('LOG_LEVEL', 'info');

if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// ============================================================
// CONFIGURAÇÕES DE EMAIL
// ============================================================

define('MAIL_FROM', getenv('MAIL_FROM') ?: 'ouvidoria@empresa.com');
define('MAIL_FROM_NAME', 'Sistema de Ouvidoria');
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_SECURE', 'tls');

// ============================================================
// CONFIGURAÇÕES DE PAGINAÇÃO
// ============================================================

define('ITEMS_PER_PAGE', 20);

// ============================================================
// TIMEZONES E LOCALIZAÇÕES
// ============================================================

define('APP_TIMEZONE', 'America/Bahia');
define('DATE_FORMAT', 'd/m/Y');
define('DATETIME_FORMAT', 'd/m/Y H:i:s');

date_default_timezone_set(APP_TIMEZONE);

// ============================================================
// ENUMS
// ============================================================

define('TIPOS_DENUNCIA', [
    'assedio_moral'      => 'Assédio Moral',
    'assedio_sexual'     => 'Assédio Sexual',
    'discriminacao'      => 'Discriminação',
    'fraude'             => 'Fraude',
    'conflito_interesse' => 'Conflito de Interesse',
    'conduta_inadequada' => 'Conduta Inadequada',
    'furto'              => 'Furto',
    'etica'              => 'Questão Ética',
    'outro'              => 'Outro',
]);

define('LOCAIS_DENUNCIA', [
    'producao'       => 'Produção',
    'expedicao'      => 'Expedição',
    'logistica'      => 'Logística',
    'administrativo' => 'Administrativo',
    'externo'        => 'Externo',
    'outro'          => 'Outro',
]);

define('STATUS_DENUNCIA', [
    'registrada'       => 'Registrada',
    'em_analise'       => 'Em Análise',
    'em_investigacao'  => 'Em Investigação',
    'concluida'        => 'Concluída',
    'arquivada'        => 'Arquivada',
]);

define('IMPACTOS', [
    'seguranca'        => 'Segurança',
    'saude'            => 'Saúde',
    'financeiro'       => 'Financeiro',
    'reputacao'        => 'Reputação',
    'ambiental'        => 'Ambiental',
    'recursos_humanos' => 'Recursos Humanos',
]);

// ============================================================
// TRATAMENTO DE ERROS
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0'); // [CORRIGIDO] string, não int
ini_set('log_errors', '1');
ini_set('error_log', LOG_DIR . 'error.log');

// ============================================================
// HEADERS DE SEGURANÇA
// [CORRIGIDO] Headers movidos para api.php — config.php é incluído por vários
// contextos (CLI, scripts) onde headers não fazem sentido.
// ============================================================
