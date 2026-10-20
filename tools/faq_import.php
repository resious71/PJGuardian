<?php

declare(strict_types=1);

/**
 * FAQ 가져오기(X16): 시드 파일(JSON)의 질문·답변을 FAQ 표(faq_items, 029)에 넣는다.
 *   php tools/faq_import.php [--file=resources/faq/seed.ko.json] --dry-run        미리 보기(바꾸지 않음)
 *   php tools/faq_import.php [--file=…] [--draft] [--config=<config.ini>]          등록(--draft면 모두 비공개 — 화면에서 검토 후 공개 전환)
 *
 * - 파일 형식: {"version":1,"items":[{"category","question","answer"}]} — 순서가 표시 순서(분류 안).
 * - 여러 번 실행해도 안전(멱등): 같은 (분류, 질문)이 이미 있으면 건너뛴다. 수정·삭제·덮어쓰기는 하지 않는다(관리자가 화면에서 고친 내용 보존).
 * - 검증은 화면과 같은 FaqService 규칙(길이·NFC·공백 정리·500개 상한). 실패한 항목이 하나라도 있으면 목록을 보이고 아무것도 쓰지 않는다(한 트랜잭션).
 * - 감사 로그: 행위자 cli, 한 줄 faq.import(추가·건너뜀 건수, 비공개 여부, 파일 이름).
 * 종료 코드: 0 성공(추가 0건 포함), 1 검증 실패, 2 사용법·설정·파일·마이그레이션(029) 오류.
 * 주의: 기본 설정(config/config.ini)은 운영·개발 DB를 가리킨다. 시험은 --config로 격리 설치본에서만 한다.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Support\Config;
use PJGuardian\Support\SchemaFeatures;

$opt = getopt('', ['file:', 'dry-run', 'draft', 'config:', 'help']);
if (isset($opt['help'])) {
    fwrite(STDOUT, "사용법: php tools/faq_import.php [--file=<시드 JSON>] [--dry-run] [--draft] [--config=<config.ini>]\n");
    exit(0);
}
$file = (string) ($opt['file'] ?? PJG_ROOT . '/resources/faq/seed.ko.json');
$dry = isset($opt['dry-run']);
$draft = isset($opt['draft']);

$raw = is_file($file) ? @file_get_contents($file) : false;
$data = $raw === false ? null : json_decode($raw, true);
if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['items'] ?? null) || !array_is_list($data['items'])) {
    fwrite(STDERR, "오류: 시드 파일을 읽을 수 없거나 형식이 다릅니다({\"version\":1,\"items\":[…]}): " . basename($file) . "\n");
    exit(2);
}

try {
    $app = App::create(PJG_ROOT, isset($opt['config']) ? Config::load((string) $opt['config']) : null);
    if (!SchemaFeatures::faq($app->db())) {
        fwrite(STDERR, "오류: FAQ 표(마이그레이션 029)가 없습니다. 먼저 php tools/migrate.php 를 실행하세요. 아무것도 쓰지 않았습니다.\n");
        exit(2);
    }
    $r = $app->faq()->import(Actor::cli(), $data['items'], $draft, $dry, basename($file));
} catch (\Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(2);
}

if ($r['errors'] !== []) {
    fwrite(STDERR, '검증 실패 ' . count($r['errors']) . "건 — 아무것도 쓰지 않았습니다.\n");
    foreach ($r['errors'] as $err) {
        fwrite(STDERR, sprintf("  %s: %s%s\n", $err['index'] < 0 ? '전체' : '항목 ' . ($err['index'] + 1), $err['code'], $err['field'] !== null ? ' (' . $err['field'] . ')' : ''));
    }
    exit(1);
}
$mode = $dry ? '미리 보기(쓰지 않음)' : ($draft ? '등록(비공개)' : '등록(공개)');
printf("%s: 추가 %d건, 건너뜀(이미 있음) %d건 — %s\n", $mode, count($r['added']), count($r['skipped']), basename($file));
foreach ($r['added'] as $a) {
    printf("  + [%s] %s\n", $a['category'], $a['question']);
}
foreach ($r['skipped'] as $s) {
    printf("  = [%s] %s\n", $s['category'], $s['question']);
}
if ($dry) {
    echo "실제로 넣으려면 --dry-run 없이 실행하세요(먼저 --draft로 넣고 화면에서 검토한 뒤 공개 전환을 권장).\n";
}
exit(0);
