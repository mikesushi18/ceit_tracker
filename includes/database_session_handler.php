<?php

final class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function __construct(private mysqli $connection)
    {
        $created = $this->connection->query(
            'CREATE TABLE IF NOT EXISTS app_sessions (
                session_id VARCHAR(128) NOT NULL PRIMARY KEY,
                session_data MEDIUMBLOB NOT NULL,
                expires_at INT UNSIGNED NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_app_sessions_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        if (!$created) {
            throw new RuntimeException('Unable to initialize persistent sessions: ' . $this->connection->error);
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $statement = $this->connection->prepare(
            'SELECT session_data FROM app_sessions WHERE session_id = ? AND expires_at > ?'
        );
        if (!$statement) {
            error_log('Unable to prepare persistent session read: ' . $this->connection->error);
            return false;
        }
        $now = time();
        $statement->bind_param('si', $id, $now);
        if (!$statement->execute()) {
            error_log('Unable to read persistent session: ' . $statement->error);
            $statement->close();
            return false;
        }
        $data = $statement->get_result()->fetch_column();
        $statement->close();
        return is_string($data) ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        $expiresAt = time() + (int) ini_get('session.gc_maxlifetime');
        $statement = $this->connection->prepare(
            'INSERT INTO app_sessions (session_id, session_data, expires_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE session_data = VALUES(session_data), expires_at = VALUES(expires_at)'
        );
        if (!$statement) {
            error_log('Unable to prepare persistent session write: ' . $this->connection->error);
            return false;
        }
        $statement->bind_param('ssi', $id, $data, $expiresAt);
        $saved = $statement->execute();
        if (!$saved) {
            error_log('Unable to save persistent session: ' . $statement->error);
        }
        $statement->close();
        return $saved;
    }

    public function destroy(string $id): bool
    {
        $statement = $this->connection->prepare('DELETE FROM app_sessions WHERE session_id = ?');
        if (!$statement) {
            error_log('Unable to prepare persistent session deletion: ' . $this->connection->error);
            return false;
        }
        $statement->bind_param('s', $id);
        $deleted = $statement->execute();
        if (!$deleted) {
            error_log('Unable to delete persistent session: ' . $statement->error);
        }
        $statement->close();
        return $deleted;
    }

    public function gc(int $maxLifetime): int|false
    {
        $cutoff = time();
        $statement = $this->connection->prepare('DELETE FROM app_sessions WHERE expires_at <= ?');
        if (!$statement) {
            error_log('Unable to prepare expired session cleanup: ' . $this->connection->error);
            return false;
        }
        $statement->bind_param('i', $cutoff);
        $deleted = $statement->execute() ? $statement->affected_rows : false;
        if ($deleted === false) {
            error_log('Unable to clean up expired sessions: ' . $statement->error);
        }
        $statement->close();
        return $deleted;
    }
}
