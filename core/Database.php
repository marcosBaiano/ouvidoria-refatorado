<?php
/**
 * Classe de Conexão com Banco de Dados
 * @author Sistema de Ouvidoria
 */

class Database
{
    private mysqli $connection;
    private string $lastQuery   = '';
    private string $lastError   = '';
    private int    $lastErrCode = 0;

    public function __construct()
    {
        $this->connect();
    }

    // ----------------------------------------------------------------
    // Conexão
    // ----------------------------------------------------------------

    private function connect(): void
    {
        // [CORRIGIDO] mysqli_report faz lançar exceções em vez de warnings silenciosos
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $this->connection = new mysqli(
                DB_HOST,
                DB_USER,
                DB_PASS,
                DB_NAME,
                DB_PORT
            );
            $this->connection->set_charset(DB_CHARSET);
        } catch (mysqli_sql_exception $e) {
            $this->logError('Conexão: ' . $e->getMessage());
            // [CORRIGIDO] die() substituído por Exception — permite tratamento no nível superior
            throw new RuntimeException('Erro ao conectar ao banco de dados.');
        }
    }

    // ----------------------------------------------------------------
    // Execução de queries
    // ----------------------------------------------------------------

    /**
     * Executa query com parâmetros opcionais.
     *
     * @param  string  $query
     * @param  array   $params
     * @param  string  $types  Detectado automaticamente se omitido
     * @return mysqli_result|bool
     */
    public function query(string $query, array $params = [], string $types = ''): mysqli_result|bool
    {
        $this->lastQuery = $query;

        try {
            if (empty($params)) {
                return $this->connection->query($query);
            }

            $stmt = $this->connection->prepare($query);

            if (empty($types)) {
                $types = $this->detectTypes($params);
            }

            $stmt->bind_param($types, ...$params);
            $stmt->execute();

            // [CORRIGIDO] get_result() retorna false p/ queries não-SELECT; trata ambos
            $result = $stmt->get_result();
            return $result !== false ? $result : true;

        } catch (mysqli_sql_exception $e) {
            $this->lastError   = $e->getMessage();
            $this->lastErrCode = $e->getCode();
            $this->logError('Query: ' . $e->getMessage() . ' | SQL: ' . $query);
            return false;
        }
    }

    // ----------------------------------------------------------------
    // CRUD helpers
    // ----------------------------------------------------------------

    /**
     * Insere um registro e retorna o insert_id ou false.
     */
    /**
     * Insere um registro.
     *
     * Retorna true em caso de sucesso, false em caso de falha.
     *
     * IMPORTANTE: insert_id do mysqli é sempre 0 para tabelas com PRIMARY KEY
     * do tipo VARCHAR/UUID (sem AUTO_INCREMENT). Por isso retornamos bool
     * baseado em affected_rows, e o chamador é responsável por já conhecer
     * o ID que foi gerado e passado no array $data.
     */
    public function insert(string $table, array $data): bool
    {
        $table  = $this->escapeIdentifier($table);
        $cols   = implode(', ', array_map([$this, 'escapeIdentifier'], array_keys($data)));
        $marks  = implode(', ', array_fill(0, count($data), '?'));
        $values = array_values($data);

        $sql  = "INSERT INTO {$table} ({$cols}) VALUES ({$marks})";

        try {
            $stmt = $this->connection->prepare($sql);

            if (!$stmt) {
                $this->lastError = $this->connection->error;
                return false;
            }

            $stmt->bind_param($this->detectTypes($values), ...$values);
            $stmt->execute();

            return $stmt->affected_rows > 0;

        } catch (mysqli_sql_exception $e) {
            $this->lastError   = $e->getMessage();
            $this->lastErrCode = $e->getCode();
            $this->logError('Insert: ' . $e->getMessage() . ' | Table: ' . $table);
            return false;
        }
    }

    /**
     * Atualiza registros e retorna o número de linhas afetadas.
     */
    public function update(string $table, array $data, array $where): int|false
    {
        $table  = $this->escapeIdentifier($table);
        $set    = [];
        $values = [];

        foreach ($data as $col => $val) {
            $set[]    = $this->escapeIdentifier($col) . ' = ?';
            $values[] = $val;
        }

        $whereClauses = [];
        foreach ($where as $col => $val) {
            $whereClauses[] = $this->escapeIdentifier($col) . ' = ?';
            $values[]       = $val;
        }

        $sql  = 'UPDATE ' . $table
              . ' SET '   . implode(', ', $set)
              . ' WHERE ' . implode(' AND ', $whereClauses);

        $stmt = $this->connection->prepare($sql);

        if (!$stmt) {
            $this->lastError = $this->connection->error;
            return false;
        }

        $stmt->bind_param($this->detectTypes($values), ...$values);
        $stmt->execute();

        return $stmt->affected_rows;
    }

    /**
     * Remove registros e retorna o número de linhas afetadas.
     */
    public function delete(string $table, array $where): int|false
    {
        $table        = $this->escapeIdentifier($table);
        $whereClauses = [];
        $values       = [];

        foreach ($where as $col => $val) {
            $whereClauses[] = $this->escapeIdentifier($col) . ' = ?';
            $values[]       = $val;
        }

        $sql  = 'DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', $whereClauses);
        $stmt = $this->connection->prepare($sql);

        if (!$stmt) {
            $this->lastError = $this->connection->error;
            return false;
        }

        $stmt->bind_param($this->detectTypes($values), ...$values);
        $stmt->execute();

        return $stmt->affected_rows;
    }

    // ----------------------------------------------------------------
    // Fetch helpers
    // ----------------------------------------------------------------

    /** Retorna um único registro ou null. */
    public function fetchOne(string $query, array $params = [], string $types = ''): ?array
    {
        $result = $this->query($query, $params, $types);

        if ($result instanceof mysqli_result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }

        return null;
    }

    /** Retorna todos os registros como array. */
    public function fetchAll(string $query, array $params = [], string $types = ''): array
    {
        $result = $this->query($query, $params, $types);
        $data   = [];

        if ($result instanceof mysqli_result) {
            // [CORRIGIDO] fetch_all() é mais eficiente que loop manual
            $data = $result->fetch_all(MYSQLI_ASSOC);
        }

        return $data;
    }

    // ----------------------------------------------------------------
    // Transações
    // ----------------------------------------------------------------

    public function beginTransaction(): void
    {
        $this->connection->begin_transaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollback(): void
    {
        $this->connection->rollback();
    }

    // ----------------------------------------------------------------
    // Utilidades
    // ----------------------------------------------------------------

    public function lastInsertId(): int|string
    {
        return $this->connection->insert_id;
    }

    public function getLastError(): string
    {
        return $this->lastError ?: $this->connection->error;
    }

    public function getLastErrorCode(): int
    {
        return $this->lastErrCode ?: $this->connection->errno;
    }

    // ----------------------------------------------------------------
    // Internos
    // ----------------------------------------------------------------

    /**
     * Detecta tipos mysqli a partir dos valores.
     * NULL é mapeado para 's' — o mysqli aceita null com bind type string
     * e o converte corretamente para SQL NULL.
     */
    private function detectTypes(array $params): string
    {
        $types = '';
        foreach ($params as $param) {
            if ($param === null) {
                $types .= 's'; // NULL aceito como string pelo mysqli
            } elseif (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        return $types;
    }

    /**
     * Escapa identificadores (tabelas/colunas) com backticks.
     * [CORRIGIDO] Previne SQL injection em nomes de tabela/coluna.
     */
    private function escapeIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function logError(string $message): void
    {
        $logFile = LOG_DIR . 'database.log';
        $ts      = date(DATETIME_FORMAT);
        file_put_contents($logFile, "[{$ts}] {$message}\n", FILE_APPEND | LOCK_EX);
    }

    public function close(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
