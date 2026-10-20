<?php

declare(strict_types=1);

namespace PJGuardian\Services\Upload;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Lang;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 업로드 조립(명세 5.4, 19.10). 요청 안에서 하지 않는다: 워커(P14)가 claimNext()로 가져오고 자식 프로세스가 assembleClaimed()로
 * 조립한다(tools/process_upload.php의 assembleNext()는 둘을 한 번에).
 *
 * assemble_pending 하나를 assembling으로 가져와(조건부 UPDATE로 한 워커만) 청크를 1MB씩 이어 붙이며 SHA-256을 계산하고,
 * 총 크기를 확인한 뒤 uploads/<id>/upload.zip으로 옮긴다. 성공하면 청크를 지우고 extracting(해제 대기, sha256 기록).
 * 실패하면 failed(assemble_failed)로 바꾸고 폴더를 지운다(명세 5.4: 실패한 업로드는 다시 올린다).
 * 파일 전체를 메모리에 올리지 않는다(최대 메모리 ≈ 읽기 단위 1MB).
 */
final class UploadAssembler
{
    private const READ_BYTES = 1024 * 1024;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly UploadFiles $files,
        private readonly Logger $logger,
    ) {
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    /**
     * 가장 오래된 조립 대기 업로드 하나를 조립한다. 없으면 null.
     *
     * @return array{upload_id:string, status:string, size?:int, sha256?:string, seconds:float, peak_memory:int}|null
     */
    public function assembleNext(): ?array
    {
        $id = $this->claimNext();
        return $id === null ? null : $this->assembleClaimed($id);
    }

    /**
     * 조립 대기 하나를 assembling으로 가져온다(조건부 UPDATE라 여러 워커도 한 곳만 가져감). 없으면 null.
     * 워커(P14)는 부모가 가져오고 자식 프로세스(tools/run_upload.php)가 assembleClaimed()를 부른다.
     */
    public function claimNext(): ?string
    {
        for ($try = 0; $try < 5; $try++) {
            $id = $this->db->value("SELECT id FROM uploads WHERE status = 'assemble_pending' ORDER BY created_at, id LIMIT 1");
            if ($id === null) {
                return null;
            }
            // 다른 워커가 먼저 가져갔으면 0행 → 다음 후보
            $claimed = $this->db->execute(
                "UPDATE uploads SET status = 'assembling', last_activity_at = ? WHERE id = ? AND status = 'assemble_pending'",
                [$this->now(), $id],
            );
            if ($claimed === 1) {
                return (string) $id;
            }
        }
        return null;
    }

    /**
     * 가져온(assembling) 업로드를 조립한다. 상태가 assembling이 아니면 null(이미 처리됨·취소됨).
     *
     * @return array{upload_id:string, status:string, size?:int, sha256?:string, seconds:float, peak_memory:int}|null
     */
    public function assembleClaimed(string $id): ?array
    {
        if ($this->db->value('SELECT status FROM uploads WHERE id = ?', [$id]) !== 'assembling') {
            return null;
        }
        return $this->assemble($id);
    }

    /** 조립 자식이 시간 초과·비정상 종료한 경우(워커가 부름): failed(assemble_failed), 폴더 삭제. 바꿨으면 true */
    public function failClaimed(string $id, string $reason): bool
    {
        $n = $this->db->execute(
            "UPDATE uploads SET status = 'failed', error_code = 'assemble_failed', error_message = ?, reserved_bytes = 0, last_activity_at = ? WHERE id = ? AND status = 'assembling'",
            [Lang::get('error.assemble_failed'), $this->now(), $id],
        );
        if ($n === 1) {
            $this->files->remove($id);
            $this->logger->error('업로드 조립 실패(작업 프로세스)', ['upload_id' => $id, 'reason' => $reason]);
        }
        return $n === 1;
    }

    /** @return array{upload_id:string, status:string, size?:int, sha256?:string, seconds:float, peak_memory:int} */
    private function assemble(string $id): array
    {
        $start = hrtime(true);
        memory_reset_peak_usage();
        $row = $this->db->one('SELECT * FROM uploads WHERE id = ?', [$id]);
        $lock = FileLock::acquire($this->files->lock($id));
        $target = $this->files->zip($id);
        $partial = $target . '.assembling';
        try {
            $out = @fopen($partial, 'wb');
            if ($out === false) {
                throw new \RuntimeException('조립 파일을 만들 수 없음');
            }
            $ctx = hash_init('sha256');
            $written = 0;
            try {
                for ($n = 0; $n < (int) $row['total_chunks']; $n++) {
                    $in = @fopen($this->files->part($id, $n), 'rb');
                    if ($in === false) {
                        throw new \RuntimeException("청크 {$n} 없음");
                    }
                    while (!feof($in)) {
                        $buf = fread($in, self::READ_BYTES);
                        if ($buf === false || $buf === '') {
                            break;
                        }
                        hash_update($ctx, $buf);
                        if (fwrite($out, $buf) !== strlen($buf)) {
                            fclose($in);
                            throw new \RuntimeException('조립 파일 쓰기 실패(디스크 공간?)');
                        }
                        $written += strlen($buf);
                    }
                    fclose($in);
                }
            } finally {
                fclose($out);
            }
            if ($written !== (int) $row['total_size']) {
                throw new \RuntimeException("크기 불일치(조립 {$written}, 시작 때 {$row['total_size']})");
            }
            if (!@rename($partial, $target)) {
                throw new \RuntimeException('조립 파일을 옮길 수 없음');
            }
            $sha = hash_final($ctx);
            // 조립본이 생겼으므로 청크와 청크 해시를 지운다
            for ($n = 0; $n < (int) $row['total_chunks']; $n++) {
                @unlink($this->files->part($id, $n));
                @unlink($this->files->partHash($id, $n));
            }
            $this->db->execute(
                "UPDATE uploads SET status = 'extracting', sha256 = ?, last_activity_at = ? WHERE id = ? AND status = 'assembling'",
                [$sha, $this->now(), $id],
            );
            $result = ['upload_id' => $id, 'status' => 'extracting', 'size' => $written, 'sha256' => $sha];
        } catch (\Throwable $e) {
            @unlink($partial);
            $this->db->execute(
                "UPDATE uploads SET status = 'failed', error_code = 'assemble_failed', error_message = ?, reserved_bytes = 0, last_activity_at = ? WHERE id = ?",
                [Lang::get('error.assemble_failed'), $this->now(), $id],
            );
            $this->logger->error('업로드 조립 실패', ['upload_id' => $id, 'reason' => $e->getMessage()]);
            $result = ['upload_id' => $id, 'status' => 'failed'];
        } finally {
            $lock->release();
        }
        if ($result['status'] === 'failed') {
            $this->files->remove($id);
        }
        $result += ['seconds' => round((hrtime(true) - $start) / 1e9, 2), 'peak_memory' => memory_get_peak_usage(true)];
        $this->logger->info('업로드 조립', $result);
        return $result;
    }
}
