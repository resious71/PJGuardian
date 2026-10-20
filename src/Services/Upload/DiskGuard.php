<?php

declare(strict_types=1);

namespace PJGuardian\Services\Upload;

use PJGuardian\Http\ApiException;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;

/**
 * 디스크 사전 검사(명세 19.3, 19.10). 업로드 init(P12)과 저장소 가져오기(P25)가 쓴다.
 *
 * 예약 크기(reserveFor): 압축 크기 S, 해제 상한 X(설정 extract.max_extracted_size)일 때 **S + max(S, X)**.
 *   - 받는 중: 청크 S
 *   - 조립 중(최대): 청크 S + 조립본 S = 2S (조립이 끝나면 청크를 지운다)
 *   - 해제 중(최대): 조립본 S + 해제 결과 ≤ X
 *   두 시점이 겹치지 않으므로 최대 사용량은 S + max(S, X). 프로토타입의 2S + X는 두 최대치를 더한 값이라 과하다.
 *   S ≤ 2GB, X = 10GB인 기본 설정에서는 S + 10GB(2GB 업로드면 12GB).
 *
 * 여유 판정: 실제 여유 공간 − 진행 중 업로드의 예약 합계(uploads.reserved_bytes) ≥ 이번 예약.
 *   이미 받은 청크도 여유 공간에서 빠지므로 일부 이중으로 센다(보수적, 동시에 여러 사람이 올려도 넘치지 않게).
 * 사용률: (전체 − 여유) / 전체 ≥ block_percent(90%)면 신규 업로드 차단, ≥ warn_percent(80%)면 WARN 로그.
 */
final class DiskGuard
{
    /** 예약이 살아 있는 상태(끝 상태 queued·failed·aborted는 0으로 비운다) */
    public const ACTIVE_STATUSES = ['receiving', 'assemble_pending', 'assembling', 'extracting', 'needs_confirmation'];

    /** @var \Closure(string): (float|false) */
    private \Closure $free;
    /** @var \Closure(string): (float|false) */
    private \Closure $total;

    /**
     * @param (\Closure(string): (float|false))|null $free  시험용(여유 공간 모의)
     * @param (\Closure(string): (float|false))|null $total 시험용
     */
    public function __construct(
        private readonly Db $db,
        private readonly UploadSettings $settings,
        private readonly Logger $logger,
        ?\Closure $free = null,
        ?\Closure $total = null,
    ) {
        $this->free = $free ?? static fn (string $dir): float|false => @disk_free_space($dir);
        $this->total = $total ?? static fn (string $dir): float|false => @disk_total_space($dir);
    }

    public function reserveFor(int $size): int
    {
        return $size + max($size, $this->settings->extractMaxBytes);
    }

    public function reservedTotal(): int
    {
        $in = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        return (int) $this->db->value("SELECT COALESCE(SUM(reserved_bytes), 0) FROM uploads WHERE status IN ({$in})", self::ACTIVE_STATUSES);
    }

    /**
     * $dir가 있는 디스크에 $need 바이트를 예약할 수 있는지. 안 되면 507.
     * insufficient_storage(필요량·여유량을 문구에), disk_usage_blocked(사용률 차단).
     */
    public function check(string $dir, int $need): void
    {
        $free = ($this->free)($dir);
        $total = ($this->total)($dir);
        if ($free === false || $total === false || $total <= 0) {
            $this->logger->error('디스크 여유 공간을 읽을 수 없음', ['dir' => $dir]);
            throw new ApiException(507, 'insufficient_storage', ['need' => self::gb($need), 'free' => '?']);
        }
        $usedPct = (1 - $free / $total) * 100;
        if ($usedPct >= $this->settings->blockPercent) {
            $this->logger->warn('디스크 사용률 차단 기준 초과로 신규 업로드 거부', ['used_percent' => round($usedPct, 1), 'block_percent' => $this->settings->blockPercent]);
            throw new ApiException(507, 'disk_usage_blocked', ['percent' => $this->settings->blockPercent]);
        }
        if ($usedPct >= $this->settings->warnPercent) {
            $this->logger->warn('디스크 사용률 경고 기준 초과', ['used_percent' => round($usedPct, 1), 'warn_percent' => $this->settings->warnPercent]);
        }
        $available = (int) $free - $this->reservedTotal();
        if ($available < $need) {
            throw new ApiException(507, 'insufficient_storage', ['need' => self::gb($need), 'free' => self::gb(max(0, $available))]);
        }
    }

    /**
     * 여유 공간만 본다(사용률 차단 없음): 분석 시작 전 출력 한도 + 여유(P15, 명세 19.3). 사용률 90% 차단은 신규 업로드·가져오기에만 적용한다.
     * 부족하면 507 insufficient_storage.
     */
    public function checkFree(string $dir, int $need): void
    {
        $free = ($this->free)($dir);
        if ($free === false) {
            $this->logger->error('디스크 여유 공간을 읽을 수 없음', ['dir' => $dir]);
            throw new ApiException(507, 'insufficient_storage', ['need' => self::gb($need), 'free' => '?']);
        }
        $available = (int) $free - $this->reservedTotal();
        if ($available < $need) {
            $this->logger->warn('디스크 여유 부족으로 분석을 시작하지 않음', ['need' => $need, 'available' => $available]);
            throw new ApiException(507, 'insufficient_storage', ['need' => self::gb($need), 'free' => self::gb(max(0, $available))]);
        }
    }

    public static function gb(int $bytes): string
    {
        return number_format($bytes / 1024 ** 3, 1) . 'GB';
    }
}
