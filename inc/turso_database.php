<?php
/**
 * Turso (libSQL) HTTP API PDO-compatible adapter.
 *
 * Wraps Turso's HTTP v2 pipeline API behind a PDO-like interface so the rest
 * of the app can switch from local SQLite to a remote Turso database with a
 * single environment-variable change (TURSO_DATABASE_URL + TURSO_AUTH_TOKEN).
 *
 * Transactions are emulated: each statement is sent individually, and
 * beginTransaction/commit/rollBack are tracked for compatibility.
 *
 * Because the app uses prepare()+execute() extensively, the statement class
 * executes the query on execute() rather than on prepare().
 */

class TursoStatement {
    private TursoDatabase $db;
    private string $sql;
    private array $params = [];
    /** @var array<int, array> Fetched rows (populated on execute). */
    private array $rows = [];
    private int $cursor = 0;
    private int $affectedRows = 0;

    public function __construct(TursoDatabase $db, string $sql) {
        $this->db = $db;
        $this->sql = $sql;
    }

    public function bindValue($param, $value, int $type = PDO::PARAM_STR): bool {
        $this->params[$param] = $value;
        return true;
    }

    /**
     * Execute the prepared statement against Turso.
     * This actually sends the HTTP request — it does NOT just store params.
     */
    public function execute(?array $params = null): bool {
        if ($params !== null) {
            foreach ($params as $key => $value) {
                $this->params[$key] = $value;
            }
        }
        return $this->db->_executeStatement($this);
    }

    public function fetch(int $mode = PDO::FETCH_ASSOC): ?array {
        if ($this->cursor >= count($this->rows)) {
            return null;
        }
        $row = $this->rows[$this->cursor++];
        return $row;
    }

    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array {
        $result = [];
        while ($row = $this->fetch($mode)) {
            $result[] = $row;
        }
        return $result;
    }

    public function fetchColumn(int $col = 0) {
        $row = $this->fetch(PDO::FETCH_NUM);
        if ($row === null || !isset($row[$col])) {
            return false;
        }
        return $row[$col];
    }

    public function rowCount(): int {
        return $this->affectedRows;
    }

    public function getSql(): string {
        return $this->sql;
    }

    public function getParams(): array {
        return $this->params;
    }

    /** Called by TursoDatabase after the HTTP response is processed. */
    public function _setResults(array $rows, int $affectedRows): void {
        $this->rows = $rows;
        $this->affectedRows = $affectedRows;
        $this->cursor = 0;
    }
}

class TursoDatabase {
    private string $url;
    private string $token;
    private bool $inTransaction = false;
    private ?string $lastInsertIdVal = null;
    private ?string $lastErrorCode = null;
    private ?string $lastErrorInfo = null;

    public function __construct(string $url, string $token) {
        $this->url = rtrim($url, '/');
        $this->token = $token;
    }

    // ─── PDO interface ────────────────────────────────────────────────

    public function prepare(string $sql): TursoStatement {
        return new TursoStatement($this, $sql);
    }

    /**
     * Execute a raw SQL query (no parameters). Used for DDL and simple SELECT.
     */
    public function query(string $sql): TursoStatement {
        $stmt = new TursoStatement($this, $sql);
        $this->_executeStatement($stmt);
        return $stmt;
    }

    /**
     * Execute a SQL statement directly. Returns affected row count.
     */
    public function exec(string $sql): int {
        $stmt = new TursoStatement($this, $sql);
        $this->_executeStatement($stmt);
        return $stmt->rowCount();
    }

    public function beginTransaction(): bool {
        $this->inTransaction = true;
        return true;
    }

    public function commit(): bool {
        $this->inTransaction = false;
        return true;
    }

    public function rollBack(): bool {
        $this->inTransaction = false;
        return true;
    }

    public function lastInsertId(?string $name = null): string|false {
        return $this->lastInsertIdVal !== null ? $this->lastInsertIdVal : '0';
    }

    public function setAttribute(int $attribute, mixed $value): bool {
        return true;
    }

    public function errorCode(): ?string {
        return $this->lastErrorCode;
    }

    public function errorInfo(): ?string {
        return $this->lastErrorInfo;
    }

    // ─── Internal execution ───────────────────────────────────────────

    /**
     * Execute a TursoStatement against Turso's HTTP API.
     * Called by both query() and TursoStatement::execute().
     */
    public function _executeStatement(TursoStatement $stmt): bool {
        $sql = $stmt->getSql();
        $params = $stmt->getParams();
        $result = $this->pipeline([$this->_buildRequest($sql, $params)]);

        if ($result === null) {
            $stmt->_setResults([], 0);
            return false;
        }

        $stmt->_setResults($result['rows'] ?? [], $result['affected_rows'] ?? 0);
        return true;
    }

    /**
     * Build a single pipeline request object.
     */
    private function _buildRequest(string $sql, array $params = []): array {
        $args = [];
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $type = 'integer';
            } elseif (is_float($value)) {
                $type = 'float';
            } elseif (is_null($value)) {
                $type = 'null';
            } else {
                $type = 'text';
            }
            $args[] = [
                'type' => $type,
                'value' => (string) $value,
            ];
        }

        return [
            'type' => 'execute',
            'stmt' => [
                'sql' => $sql,
                'args' => $args,
            ],
        ];
    }

    /**
     * Send a batch of requests to Turso's HTTP v2 pipeline endpoint.
     *
     * @param array<int, array> $requests
     * @return array{rows: array, affected_rows: int}|null
     */
    private function pipeline(array $requests): ?array {
        $endpoint = $this->url . '/v2/pipeline';
        $payload = json_encode(['requests' => $requests]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError) {
            $this->lastErrorCode = 'CURL_ERROR';
            $this->lastErrorInfo = $curlError ?: 'HTTP request failed';
            error_log('Turso HTTP error: ' . ($curlError ?: "HTTP $httpCode"));
            return null;
        }

        if ($httpCode >= 400) {
            $this->lastErrorCode = "HTTP_$httpCode";
            $this->lastErrorInfo = $response;
            error_log("Turso HTTP $httpCode: " . substr($response, 0, 500));
            return null;
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            $this->lastErrorCode = 'JSON_ERROR';
            $this->lastErrorInfo = 'Failed to decode Turso response';
            return null;
        }

        // Process the results
        $results = $decoded['results'] ?? [];
        $allRows = [];
        $totalAffected = 0;

        foreach ($results as $result) {
            if (!isset($result['response'])) {
                continue;
            }
            $resp = $result['response'];

            if (isset($resp['error'])) {
                $this->lastErrorCode = $resp['error']['code'] ?? 'TURSO_ERROR';
                $this->lastErrorInfo = $resp['error']['message'] ?? 'Unknown Turso error';
                error_log('Turso query error: ' . $this->lastErrorInfo);
                return null;
            }

            if (isset($resp['result'])) {
                $r = $resp['result'];

                if (isset($r['cols']) && isset($r['rows'])) {
                    $cols = $r['cols'];
                    foreach ($r['rows'] as $row) {
                        $rowData = [];
                        for ($i = 0; $i < count($cols); $i++) {
                            $colName = $cols[$i]['name'] ?? "col_$i";
                            $rowData[$colName] = $row[$i] ?? null;
                        }
                        $allRows[] = $rowData;
                    }
                }

                if (isset($r['affected_row_count'])) {
                    $totalAffected += (int) $r['affected_row_count'];
                }

                if (isset($r['last_insert_rowid'])) {
                    $this->lastInsertIdVal = (string) $r['last_insert_rowid'];
                }
            }
        }

        return ['rows' => $allRows, 'affected_rows' => $totalAffected];
    }
}
