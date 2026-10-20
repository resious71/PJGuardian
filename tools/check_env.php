<?php

declare(strict_types=1);

/**
 * PJGuardian 환경 점검 (P02)
 *
 * 사용법: php tools/check_env.php
 * 항목마다 OK / WARN / FAIL을 출력하고, FAIL이 하나라도 있으면 종료 코드 1을 반환한다.
 *
 * 설정은 config/config.ini(없으면 config.ini.example)를 제품 코드의 설정 검증으로 읽는다.
 * 외부 명령은 proc_open에 인자 배열로만 실행한다(셸 없음). 이 스크립트는 서버에 설치된
 * Python·Node.js 런타임 버전을 확인하기 위해 PATH의 python/node도 실행한다.
 * P20A: 프로세스를 띄우지 않는 항목(OS·PHP·확장·SQLite·php.ini·폴더 쓰기·디스크)은 관리자 점검 화면과 같은
 * Support\EnvironmentCheck를 쓴다(중복 구현하지 않음). 런타임·bin 실행 확인은 이 CLI에만 있다.
 */

namespace PJGuardian\Tools;

const ROOT = __DIR__ . '/..';

final class Report
{
    /** @var array<string,int> */
    public array $counts = ['OK' => 0, 'WARN' => 0, 'FAIL' => 0];

    public function section(string $title): void
    {
        echo PHP_EOL . '== ' . $title . PHP_EOL;
    }

    public function add(string $status, string $item, string $detail = ''): void
    {
        $this->counts[$status]++;
        // 한글은 화면에서 2칸을 차지하므로 표시 폭 기준으로 맞춘다
        $pad = max(0, 34 - mb_strwidth($item, 'UTF-8'));
        printf("[%-4s] %s%s %s%s", $status, $item, str_repeat(' ', $pad), $detail, PHP_EOL);
    }
}

/**
 * 제품 코드의 설정 검증(src/Support/Config)으로 읽는다. 검증에 실패하면 FAIL과 원인을 보여 주고,
 * 이후 점검은 기본값으로 계속한다. PHP 8.2 미만이면 제품 코드를 불러오지 않는다.
 */
function loadConfig(Report $r): ?\PJGuardian\Support\Config
{
    $ini = ROOT . '/config/config.ini';
    $src = 'config/config.ini';
    if (!is_file($ini)) {
        $ini = ROOT . '/config/config.ini.example';
        $src = 'config/config.ini.example (config.ini 없음 — 복사해서 만드세요)';
    }
    try {
        $config = \PJGuardian\Support\Config::load($ini, ROOT . '/config/secrets.ini', getenv());
    } catch (\PJGuardian\Support\ConfigException $e) {
        $r->add('FAIL', '설정 파일', $src . ' — ' . implode(' / ', $e->errors));
        return null;
    }
    $r->add(is_file(ROOT . '/config/config.ini') ? 'OK' : 'WARN', '설정 파일', $src . ' (검증 통과)');
    return $config;
}

/** 설정 값(크기는 바이트, 기간은 초). 설정을 읽지 못했으면 기본값. */
function cfg(?\PJGuardian\Support\Config $cfg, string $name, int $default): int
{
    return $cfg !== null ? $cfg->int($name) : $default;
}

/** EnvironmentCheck 항목 하나를 출력한다(라벨·설명은 문자열 리소스) */
function report(Report $r, array $it): void
{
    $label = \PJGuardian\Support\Lang::get('env.item.' . $it['id'], $it['params']);
    $note = $it['note'] !== null ? ' — ' . \PJGuardian\Support\Lang::get('env.note.' . $it['note'], $it['params']) : '';
    $r->add(strtoupper($it['status']), $label, $it['value'] . $note);
}

function human(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return sprintf('%.1f %s', $bytes, $units[$i]);
}

/**
 * 외부 명령을 셸 없이 실행한다. 출력은 임시 파일로 받아 파이프 교착을 피한다
 * (Windows에서는 proc_open 파이프에 stream_select를 쓸 수 없다).
 *
 * @param list<string> $cmd
 * @return array{ok:bool, code:int, out:string, err:string}
 */
function run(array $cmd, ?string $cwd = null, int $timeoutSec = 15): array
{
    $outFile = tempnam(sys_get_temp_dir(), 'pjg');
    $errFile = tempnam(sys_get_temp_dir(), 'pjg');
    $spec = [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']];
    $proc = @proc_open($cmd, $spec, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        @unlink($outFile);
        @unlink($errFile);
        return ['ok' => false, 'code' => -1, 'out' => '', 'err' => '실행할 수 없음'];
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + $timeoutSec;
    $code = -1;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $code = $st['exitcode'];
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc);
            $code = -2;
            break;
        }
        usleep(50_000);
    }
    proc_close($proc);
    $out = trim((string) file_get_contents($outFile));
    $err = trim((string) file_get_contents($errFile));
    @unlink($outFile);
    @unlink($errFile);
    return ['ok' => $code === 0, 'code' => $code, 'out' => $out, 'err' => $err];
}

function firstLine(string $s): string
{
    return strtok($s, "\r\n") ?: '';
}

/** @return list<string> semver 내림차순으로 정렬한 버전 디렉토리 이름 */
function versionDirs(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $versions = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (preg_match('/^\d+\.\d+\.\d+$/', $name) && is_dir($dir . '/' . $name)) {
            $versions[] = $name;
        }
    }
    usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
    return $versions;
}

// ---------------------------------------------------------------------------

$r = new Report();
$isWindows = PHP_OS_FAMILY === 'Windows';
$osKey = $isWindows ? 'windows' : 'linux';

echo 'PJGuardian 환경 점검  ' . gmdate('Y-m-d\TH:i:s\Z') . PHP_EOL;

if (PHP_VERSION_ID < 80200) {
    $r->section('OS와 PHP');
    $r->add('FAIL', 'PHP 버전', PHP_VERSION . ' — 8.2 이상 필요(ZipArchive::getStreamIndex, 명세 19.10). 나머지 점검을 건너뜁니다');
    exit(1);
}
require_once ROOT . '/src/bootstrap.php';

$r->section('설정');
$cfg = loadConfig($r);
$chunkBytes = cfg($cfg, 'upload.chunk_size', 8 * 1024 ** 2);

$r->section('OS·PHP·확장 (Support\\EnvironmentCheck)');
foreach (\PJGuardian\Support\EnvironmentCheck::platform() as $it) {
    report($r, $it);
}
$r->add('OK', 'php.ini', php_ini_loaded_file() ?: '(없음)');

$r->section('php.ini 값 (청크 ' . human($chunkBytes) . ' 기준, docs/xampp_upload_settings.md)');
echo '  ※ 아래 값은 이 CLI가 읽은 php.ini 기준이다. Apache가 다른 php.ini를 쓰면 웹 값은 다를 수 있다' . PHP_EOL;
echo '    (XAMPP Windows는 CLI와 Apache가 같은 php.ini를 쓴다).' . PHP_EOL;
foreach (\PJGuardian\Support\EnvironmentCheck::ini($chunkBytes) as $it) {
    report($r, $it);
}
$iniFile = php_ini_loaded_file();
$iniRaw = $iniFile !== false ? (@parse_ini_file($iniFile, false, INI_SCANNER_RAW) ?: []) : [];
$mit = isset($iniRaw['max_input_time']) ? (int) $iniRaw['max_input_time'] : -1;
$r->add('OK', 'max_input_time(php.ini)', $mit . '초 (청크는 원시 본문(PUT)으로 받는다. 이 값이 PUT 본문 수신에 적용되는지는 미확인)');
$tmpDir = (string) (ini_get('upload_tmp_dir') ?: sys_get_temp_dir());
$r->add(is_dir($tmpDir) && is_writable($tmpDir) ? 'OK' : 'WARN', 'upload_tmp_dir', $tmpDir . (is_dir($tmpDir) && is_writable($tmpDir) ? '' : ' — 없거나 쓸 수 없음'));
echo '  ※ Apache LimitRequestBody(2.4.53 이상 기본 1GiB)와 Timeout(기본 60초)은 CLI에서 확인할 수 없다. 청크 크기보다 크면 된다.' . PHP_EOL;

$r->section('런타임');
$python = null;
foreach ([['python3', '--version'], ['python', '--version'], ['py', '-3', '--version']] as $cmd) {
    $res = run($cmd);
    $line = firstLine($res['out'] !== '' ? $res['out'] : $res['err']);
    if ($res['ok'] && preg_match('/^Python (3\.\d+\.\d+)/', $line, $m)) {
        $python = $m[1];
        $r->add('OK', 'Python 3', $python . ' (' . $cmd[0] . ')');
        break;
    }
}
if ($python === null) {
    $r->add('FAIL', 'Python 3', 'PATH에서 찾을 수 없음 (명세 2)');
}
$node = run(['node', '--version']);
$nodeVer = $node['ok'] ? firstLine($node['out']) : null;
if ($nodeVer !== null) {
    $r->add('OK', 'Node.js', $nodeVer);
} else {
    $r->add('FAIL', 'Node.js', 'PATH에서 찾을 수 없음 — ESLint 실행에 필요 (명세 2, 12). docs/tool_setup.md 참고');
}

$r->section('분석 도구 (bin/)');
$ruffVersions = versionDirs(ROOT . '/bin/ruff');
$ruffFound = false;
foreach ($ruffVersions as $v) {
    $exe = ROOT . "/bin/ruff/$v/$osKey/ruff" . ($isWindows ? '.exe' : '');
    if (!is_file($exe)) {
        continue;
    }
    $ruffFound = true;
    $res = run([realpath($exe) ?: $exe, '--version']);
    $r->add($res['ok'] ? 'OK' : 'WARN', "ruff $v ($osKey)", $res['ok'] ? firstLine($res['out']) : '실행 실패: ' . firstLine($res['err']));
}
if (!$ruffFound) {
    $r->add('WARN', 'ruff', "bin/ruff/<버전>/$osKey/ 에 실행 파일 없음 — docs/tool_setup.md 참고");
}

$eslintVersions = versionDirs(ROOT . '/bin/eslint');
$eslintFound = false;
foreach ($eslintVersions as $v) {
    $js = ROOT . "/bin/eslint/$v/node_modules/eslint/bin/eslint.js";
    if (!is_file($js)) {
        continue;
    }
    $eslintFound = true;
    if ($nodeVer === null) {
        $r->add('WARN', "eslint $v", 'Node.js가 없어 실행 확인 불가');
        continue;
    }
    $res = run(['node', realpath($js) ?: $js, '--version'], ROOT . "/bin/eslint/$v");
    $r->add($res['ok'] ? 'OK' : 'WARN', "eslint $v", $res['ok'] ? firstLine($res['out']) : '실행 실패: ' . firstLine($res['err']));
}
if (!$eslintFound) {
    $r->add('WARN', 'eslint', 'bin/eslint/<버전>/node_modules/eslint 없음 — docs/tool_setup.md 참고');
}

$r->section('폴더 쓰기 권한 (Support\\EnvironmentCheck)');
$dataDirs = [];
foreach (['db', 'uploads', 'work', 'snapshots', 'backups', 'logs'] as $sub) {
    $dataDirs[$sub] = ROOT . '/data/' . $sub;
}
foreach (\PJGuardian\Support\EnvironmentCheck::directories($dataDirs, ROOT . '/bin', false) as $it) {
    report($r, $it);
}

$r->section('디스크 (data/ 기준, 명세 19.3)');
$disk = \PJGuardian\Support\EnvironmentCheck::disk(ROOT . '/data', cfg($cfg, 'disk.warn_percent', 80), cfg($cfg, 'disk.block_percent', 90));
if ($disk['free'] === null) {
    $r->add('WARN', '디스크 여유 공간', '확인할 수 없음');
} else {
    $free = $disk['free'];
    $r->add(strtoupper($disk['status']), '디스크 사용률', sprintf('%.1f%% 사용 (경고 %d%%, 차단 %d%%)', $disk['used_percent'], cfg($cfg, 'disk.warn_percent', 80), cfg($cfg, 'disk.block_percent', 90)));
    // 명세 19.3 용량 산정식 중 분석 작업 부분: 동시 분석 수 × (업로드 + 압축 해제 상한)
    $need = cfg($cfg, 'analysis.max_concurrent', 2)
        * (cfg($cfg, 'upload.max_zip_size', 2 * 1024 ** 3) + cfg($cfg, 'extract.max_extracted_size', 10 * 1024 ** 3));
    $r->add($free >= $need ? 'OK' : 'WARN', '디스크 여유 공간', human($free) . ' / ' . human((float) $disk['total']) . ' (분석 작업 최소 ' . human($need) . ', 스냅샷·백업 별도)');
}

printf('%s결과: OK %d, WARN %d, FAIL %d%s', PHP_EOL, $r->counts['OK'], $r->counts['WARN'], $r->counts['FAIL'], PHP_EOL);
exit($r->counts['FAIL'] > 0 ? 1 : 0);
