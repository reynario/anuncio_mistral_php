<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class JobQueueService
{
    public function __construct(private readonly PDO $pdo) {}

    public function createJob(string $jobId, array $payload): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO background_jobs (id, status, payload_json, logs_json, done)
             VALUES (?, 'pending', ?, '[]', 0)"
        );
        $stmt->execute([$jobId, json_encode($payload)]);
    }

    public function pushLog(string $jobId, string $level, string $msg): void
    {
        $entry = json_encode([
            'ts'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'level' => $level,
            'msg'   => $msg,
        ]);

        $this->pdo->prepare(
            "UPDATE background_jobs
             SET logs_json = JSON_ARRAY_APPEND(logs_json, '$', CAST(? AS JSON)),
                 updated_at = NOW()
             WHERE id = ?"
        )->execute([$entry, $jobId]);
    }

    public function endJob(string $jobId, string $status, ?string $error = null): void
    {
        $this->pdo->prepare(
            "UPDATE background_jobs SET status = ?, done = 1, error = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$status, $error, $jobId]);
    }

    public function getJob(string $jobId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM background_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id'     => $row['id'],
            'status' => $row['status'],
            'done'   => (bool) $row['done'],
            'error'  => $row['error'],
            'logs'   => json_decode($row['logs_json'] ?? '[]', true),
        ];
    }

    public function claimNextPendingJob(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query(
                "SELECT * FROM background_jobs WHERE status = 'pending' AND done = 0 ORDER BY created_at ASC LIMIT 1 FOR UPDATE"
            );
            $row = $stmt->fetch();
            if (!$row) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare(
                "UPDATE background_jobs SET status = 'running', updated_at = NOW() WHERE id = ?"
            )->execute([$row['id']]);
            $this->pdo->commit();
            return array_merge($row, [
                'payload' => json_decode($row['payload_json'], true),
            ]);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function resetStuckJobs(): void
    {
        $this->pdo->exec(
            "UPDATE background_jobs SET status = 'pending', updated_at = NOW()
             WHERE status = 'running' AND done = 0"
        );
    }

    public function generateId(): string
    {
        return bin2hex(random_bytes(18));
    }
}
