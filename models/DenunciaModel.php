<?php
/**
 * Modelo de Denúncia
 * @author Sistema de Ouvidoria
 */

class DenunciaModel
{
    private Database $db;

    /** Campos editáveis via update(). [CORRIGIDO] Whitelist explícita centralizada. */
    private const UPDATABLE_FIELDS = ['tipo', 'descricao', 'local', 'status', 'testemunhas', 'data_periodo'];

    public function __construct()
    {
        $this->db = new Database();
    }

    // ----------------------------------------------------------------
    // Escrita
    // ----------------------------------------------------------------

    public function create(array $data): bool
    {
        $row = [
            'id'            => $data['id'],
            'protocolo'     => $data['protocolo'],
            'tipo'          => $data['tipo'],
            'descricao'     => $data['descricao'],
            'envolvidos'    => $data['envolvidos'],       // JSON string
            'local'         => $data['local'],
            'data_periodo'  => $data['data_periodo'] ?: null,
            'testemunhas'   => $data['testemunhas']  ?: null,
            'comunicado'    => $data['comunicado'] ? 1 : 0,
            'quem_comunicou'=> $data['quem_comunicou'] ?: null,
            'anonimato'     => $data['anonimato'] ? 1 : 0,
            'contato'       => $data['contato'] ?: null,
            'impacto'       => $data['impacto'],          // JSON string
            'ip_origem'     => $data['ip_origem'],
            'status'        => 'registrada',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        return $this->db->insert('denuncias', $row);
    }

    public function update(string $id, array $data): int|false
    {
        $updateData = array_intersect_key($data, array_flip(self::UPDATABLE_FIELDS));

        if (empty($updateData)) {
            return 0;
        }

        // Valida status se presente
        if (isset($updateData['status']) && !array_key_exists($updateData['status'], STATUS_DENUNCIA)) {
            unset($updateData['status']);
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->update('denuncias', $updateData, ['id' => $id]);
    }

    public function updateStatus(string $id, string $status): int|false
    {
        return $this->db->update('denuncias', [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    // ----------------------------------------------------------------
    // Leitura
    // ----------------------------------------------------------------

    public function getById(string $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM denuncias WHERE id = ?', [$id]);
        return $row ? $this->decodeJsonFields($row) : null;
    }

    public function getByProtocolo(string $protocolo): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM denuncias WHERE protocolo = ?', [$protocolo]);

        if (!$row) {
            return null;
        }

        $row               = $this->decodeJsonFields($row);
        $row['evidencias'] = $this->getEvidencias($row['id']);

        return $row;
    }

    /**
     * Lista denúncias com paginação e filtros.
     * [CORRIGIDO] Construção de query refatorada para evitar concatenação manual de tipos.
     */
    public function list(int $page = 1, int $limit = ITEMS_PER_PAGE, array $filters = []): array
    {
        $offset   = ($page - 1) * $limit;
        $where    = ['1=1'];
        $params   = [];

        $this->applyFilters($filters, $where, $params);

        $sql     = 'SELECT * FROM denuncias WHERE ' . implode(' AND ', $where)
                 . ' ORDER BY created_at DESC LIMIT ?, ?';
        $params[] = $offset;
        $params[] = $limit;

        $rows = $this->db->fetchAll($sql, $params);

        return array_map([$this, 'decodeJsonFields'], $rows);
    }

    public function count(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];

        $this->applyFilters($filters, $where, $params);

        $sql    = 'SELECT COUNT(*) AS total FROM denuncias WHERE ' . implode(' AND ', $where);
        $result = $this->db->fetchOne($sql, $params);

        return (int) ($result['total'] ?? 0);
    }

    public function search(string $term, int $page = 1, int $limit = ITEMS_PER_PAGE): array
    {
        $offset = ($page - 1) * $limit;
        $like   = '%' . $term . '%';

        $sql    = 'SELECT * FROM denuncias
                   WHERE protocolo   LIKE ?
                      OR descricao   LIKE ?
                      OR testemunhas LIKE ?
                   ORDER BY created_at DESC
                   LIMIT ?, ?';

        $rows = $this->db->fetchAll($sql, [$like, $like, $like, $offset, $limit], 'sssii');

        return array_map([$this, 'decodeJsonFields'], $rows);
    }

    public function exportReport(array $filters = []): array
    {
        // [CORRIGIDO] Limite explícito para evitar exports infinitos em produção
        return $this->list(1, 10000, $filters);
    }

    // ----------------------------------------------------------------
    // Evidências
    // ----------------------------------------------------------------

    public function addEvidence(string $idDenuncia, string $filename, string $originalName, int $fileSize): bool
    {
        return $this->db->insert('evidencias', [
            'id'            => Helper::generateUUID(),
            'id_denuncia'   => $idDenuncia,
            'filename'      => $filename,
            'original_name' => $originalName,
            'file_size'     => $fileSize,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function getEvidencias(string $idDenuncia): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM evidencias WHERE id_denuncia = ? ORDER BY created_at DESC',
            [$idDenuncia]
        );
    }

    public function removeEvidence(string $id): int|false
    {
        $evidence = $this->db->fetchOne('SELECT * FROM evidencias WHERE id = ?', [$id]);

        if ($evidence) {
            $path = UPLOAD_DIR . $evidence['filename'];
            // [CORRIGIDO] Verifica is_file() em vez de file_exists() para evitar remoção de diretórios
            if (is_file($path)) {
                unlink($path);
            }
        }

        return $this->db->delete('evidencias', ['id' => $id]);
    }

    // ----------------------------------------------------------------
    // Comentários
    // ----------------------------------------------------------------

    public function addComment(string $idDenuncia, string $author, string $content, bool $isInternal = false): bool
    {
        return $this->db->insert('comentarios', [
            'id'          => Helper::generateUUID(),
            'id_denuncia' => $idDenuncia,
            'author'      => $author,
            'content'     => $content,
            'is_internal' => $isInternal ? 1 : 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function getComments(string $idDenuncia, bool $internalOnly = false): array
    {
        $sql    = 'SELECT * FROM comentarios WHERE id_denuncia = ?';
        $params = [$idDenuncia];

        if ($internalOnly) {
            $sql .= ' AND is_internal = 1';
        }

        $sql .= ' ORDER BY created_at ASC';

        return $this->db->fetchAll($sql, $params);
    }

    // ----------------------------------------------------------------
    // Estatísticas
    // ----------------------------------------------------------------

    public function getStatistics(): array
    {
        $total = (int) ($this->db->fetchOne('SELECT COUNT(*) AS c FROM denuncias')['c'] ?? 0);

        $anonimas = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM denuncias WHERE anonimato = 1'
        )['c'] ?? 0);

        return [
            'total'          => $total,
            'anonimas'       => $anonimas,
            'identificadas'  => $total - $anonimas,
            'por_tipo'       => $this->db->fetchAll('SELECT tipo,  COUNT(*) AS count FROM denuncias GROUP BY tipo  ORDER BY count DESC'),
            'por_status'     => $this->db->fetchAll('SELECT status, COUNT(*) AS count FROM denuncias GROUP BY status'),
            'por_local'      => $this->db->fetchAll('SELECT local,  COUNT(*) AS count FROM denuncias GROUP BY local  ORDER BY count DESC'),
            'ultima_denuncia'=> $this->db->fetchOne('SELECT created_at FROM denuncias ORDER BY created_at DESC LIMIT 1'),
        ];
    }

    public function getLastError(): string
    {
        return $this->db->getLastError();
    }

    // ----------------------------------------------------------------
    // Internos
    // ----------------------------------------------------------------

    /** Decodifica campos JSON armazenados como string. */
    private function decodeJsonFields(array $row): array
    {
        foreach (['envolvidos', 'impacto'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $decoded      = json_decode($row[$field], true);
                $row[$field]  = is_array($decoded) ? $decoded : [];
            }
        }
        return $row;
    }

    /**
     * Aplica filtros comuns às queries de listagem e contagem.
     * [CORRIGIDO] Centraliza lógica para evitar duplicação entre list() e count().
     */
    private function applyFilters(array $filters, array &$where, array &$params): void
    {
        if (!empty($filters['tipo'])) {
            $where[]  = 'tipo = ?';
            $params[] = $filters['tipo'];
        }

        if (!empty($filters['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['local'])) {
            $where[]  = 'local = ?';
            $params[] = $filters['local'];
        }

        if (!empty($filters['data_inicio']) && !empty($filters['data_fim'])) {
            $where[]  = 'created_at BETWEEN ? AND ?';
            $params[] = $filters['data_inicio'] . ' 00:00:00';
            $params[] = $filters['data_fim']    . ' 23:59:59';
        }
    }
}
