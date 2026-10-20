<?php

declare(strict_types=1);

namespace PJGuardian\Services\Accounts;

use PJGuardian\Http\ApiException;
use PJGuardian\Repositories\UserRepository;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Auth\AuthProviderRegistry;
use PJGuardian\Services\Auth\AuthSettings;
use PJGuardian\Services\Auth\SessionService;
use PJGuardian\Services\UserProfileRules;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 계정 CSV 일괄 생성·비활성화(P28A, 명세 19.2, admin만).
 *
 * 미리보기 → 실행(서버가 판정, 서버 상태 없음):
 *   - preview(): 읽기·검증만 하고 아무것도 저장하지 않는다. 행별 판정(ok·warning·error)과 사유 코드, 요약,
 *     digest = SHA-256(파일 해시 + 인코딩 + 행별 판정)을 돌려준다.
 *   - execute(): 같은 파일을 다시 받아 같은 검증을 하고 digest가 미리보기와 같을 때만 실행한다(파일이 바뀌었거나 그사이 DB가 바뀌어
 *     판정이 달라지면 409 import_preview_stale — 아무것도 반영하지 않음). 비밀번호 해시는 쓰기 트랜잭션 밖에서 미리 만들고,
 *     트랜잭션 안에서 한 번 더 검증·digest를 확인한 뒤 전부 반영하거나 하나도 반영하지 않는다(일부 반영 없음).
 *   - 오류 행이 있으면 skip_errors(오류 행 제외)를 명시해야 실행한다(422 import_has_errors).
 * 로컬 계정은 임시 비밀번호(24시간, 첫 로그인 때 변경 강제 — P10과 같은 값)를 받고, 평문은 execute 응답에만 한 번 담는다.
 * 기존 계정 수정(역할·이름·재활성화)·그룹 지정·비밀번호 지정은 하지 않는다. 로그·감사 로그에 임시 비밀번호·이메일·CSV 원문을 남기지 않는다.
 */
final class AccountImportService
{
    private const LOGIN_ID_RE = '/^[A-Za-z0-9][A-Za-z0-9._@-]{0,99}$/'; // UserService와 같은 규칙(X06)
    /** 결과 CSV(내려받는 파일)의 수식 주입 방지로 앞에 작은따옴표가 붙을 문자 — 임시 비밀번호가 이 문자로 시작하지 않게 다시 만든다 */
    private const FORMULA_START = ['=', '+', '-', '@'];

    private readonly UserRepository $repo;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AuthSettings $settings,
        private readonly SessionService $sessions,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly AuthProviderRegistry $authTypes,
    ) {
        $this->repo = new UserRepository($db);
    }

    /** @return array<string, mixed> 미리보기(저장 없음) */
    public function preview(Actor $actor, string $bytes, string $encoding): array
    {
        $plan = $this->plan($actor, $bytes, $encoding);
        unset($plan['parsed']);
        return $plan;
    }

    /**
     * @return array<string, mixed> {summary, rows:[{row, action, login_id, result, user_id?, temp_password?, temp_password_expires_at?, codes}]}
     */
    public function execute(Actor $actor, string $bytes, string $encoding, string $digest, bool $skipErrors): array
    {
        $plan = $this->plan($actor, $bytes, $encoding);
        if ($plan['file_errors'] !== []) {
            throw new ApiException(400, 'import_file_invalid', ['codes' => array_column($plan['file_errors'], 'code')]);
        }
        if (!hash_equals($plan['digest'], $digest)) {
            throw new ApiException(409, 'import_preview_stale');
        }
        if ($plan['summary']['error'] > 0 && !$skipErrors) {
            throw new ApiException(422, 'import_has_errors', ['errors' => $plan['summary']['error']]);
        }
        if ($plan['summary']['create'] + $plan['summary']['deactivate'] === 0) {
            throw new ApiException(422, 'import_nothing_to_do');
        }
        // 임시 비밀번호와 해시는 쓰기 잠금 밖에서(bcrypt는 행마다 수십 ms)
        $secrets = [];
        foreach ($plan['rows'] as $r) {
            if ($r['plan'] === 'create' && $r['auth_type'] === 'local') {
                $temp = $this->temporaryPassword();
                $secrets[$r['row']] = [$temp, $this->settings->hash($temp)];
            }
        }
        $expires = SystemClock::iso($this->clock->now()->modify('+' . $this->settings->tempPasswordExpiry . ' seconds'));
        $now = SystemClock::iso($this->clock->now());
        $results = $this->db->transaction(function () use ($actor, $bytes, $encoding, $digest, $plan, $secrets, $expires, $now, $skipErrors): array {
            // 잠근 뒤 다시 판정 — 미리보기 이후(또는 해시를 만드는 사이) 생긴 충돌이 있으면 아무것도 반영하지 않는다
            $again = $this->plan($actor, $bytes, $encoding);
            if (!hash_equals($again['digest'], $digest)) {
                throw new ApiException(409, 'import_preview_stale');
            }
            $out = [];
            $created = 0;
            $deactivated = 0;
            foreach ($again['rows'] as $r) {
                $res = ['row' => $r['row'], 'action' => $r['action'], 'login_id' => $r['login_id'], 'codes' => $r['codes']];
                if ($r['plan'] === 'create') {
                    // 로컬은 임시 비밀번호, 외부 인증(P28B ldap)은 비밀번호 없이 승인된 상태로 — 첫 로그인 때 로그인 ID로 외부 신원과 연결
                    $local = $r['auth_type'] === 'local';
                    [$temp, $hash] = $local ? ($secrets[$r['row']] ?? throw new \LogicException('no_secret')) : [null, null];
                    $id = $this->repo->insert([
                        'login_id' => $r['login_id'], 'display_name' => $r['display_name'], 'email' => $r['email'], 'role' => $r['role'],
                        'auth_type' => $r['auth_type'], 'password_hash' => $hash, 'temp_password_expires_at' => $local ? $expires : null,
                        'must_change_password' => $local ? 1 : 0, 'password_kind' => $local ? 'temporary' : 'user', 'created_at' => $now,
                    ]);
                    $this->audit->record($actor, 'user.create', 'user', (string) $id, [
                        'login_id' => $r['login_id'], 'role' => $r['role'], 'auth_type' => $r['auth_type'], 'source' => 'csv',
                    ]);
                    $created++;
                    $out[] = $res + ['result' => 'created', 'user_id' => $id, 'role' => $r['role'], 'display_name' => $r['display_name'],
                        'auth_type' => $r['auth_type'], 'temp_password' => $temp, 'temp_password_expires_at' => $local ? $expires : null];
                } elseif ($r['plan'] === 'deactivate') {
                    $this->repo->updateStatus((int) $r['user_id'], 'inactive', $now);
                    $this->sessions->destroyForUser((int) $r['user_id']);
                    $this->audit->record($actor, 'user.deactivate', 'user', (string) $r['user_id'], ['login_id' => $r['login_id'], 'source' => 'csv']);
                    $deactivated++;
                    $out[] = $res + ['result' => 'deactivated', 'user_id' => (int) $r['user_id']];
                } else {
                    $out[] = $res + ['result' => $r['status'] === 'error' ? 'error' : 'skipped'];
                }
            }
            $this->audit->record($actor, 'user.bulk_import', null, null, [
                'created' => $created, 'deactivated' => $deactivated, 'skipped' => $again['summary']['skip'], 'errors_skipped' => $again['summary']['error'],
                'warnings' => $again['summary']['warning'], 'rows' => count($again['rows']), 'encoding' => $again['encoding'],
                'file_sha256' => substr($again['file_sha256'], 0, 12), 'skip_errors' => $skipErrors,
            ]);
            return ['rows' => $out, 'created' => $created, 'deactivated' => $deactivated, 'summary' => $again['summary']];
        });
        $this->logger->info('계정 CSV 일괄 처리', ['created' => $results['created'], 'deactivated' => $results['deactivated'],
            'rows' => count($results['rows']), 'file' => substr($plan['file_sha256'], 0, 12), 'by' => $actor->label]);
        return [
            'summary' => $results['summary'] + ['created' => $results['created'], 'deactivated' => $results['deactivated']],
            'temp_password_hours' => intdiv($this->settings->tempPasswordExpiry, 3600),
            'rows' => $results['rows'],
        ];
    }

    /** 양식(UTF-8 BOM, 머리글 + 예시 2행). $exampleName: 화면 언어의 예시 표시 이름 */
    public static function template(string $exampleName, string $exampleDeactivate): string
    {
        $q = static fn (string $v): string => preg_match('/[",\r\n]/', $v) === 1 ? '"' . str_replace('"', '""', $v) . '"' : $v;
        $lines = [
            implode(',', AccountCsvReader::COLUMNS),
            implode(',', array_map($q, ['create', 'kim.dev', $exampleName, 'kim.dev@example.com', 'user', 'local'])),
            implode(',', array_map($q, ['deactivate', $exampleDeactivate, '', '', '', ''])),
        ];
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * 읽기 + 행 검증(저장 없음).
     *
     * @return array<string, mixed>
     */
    private function plan(Actor $actor, string $bytes, string $encoding): array
    {
        $parsed = AccountCsvReader::read($bytes, $encoding);
        $fileSha = hash('sha256', $bytes);
        $rows = [];
        $summary = ['rows' => 0, 'create' => 0, 'deactivate' => 0, 'skip' => 0, 'error' => 0, 'warning' => 0, 'ok' => 0, 'admin_create' => 0];
        if ($parsed['errors'] === []) {
            $rows = $this->validate($actor, $parsed['rows']);
            foreach ($rows as $r) {
                $summary['rows']++;
                $summary[$r['status']]++;
                if ($r['plan'] === 'create' || $r['plan'] === 'deactivate') {
                    $summary[$r['plan']]++;
                } elseif ($r['status'] !== 'error') {
                    $summary['skip']++;
                }
                if ($r['plan'] === 'create' && $r['role'] === 'admin') {
                    $summary['admin_create']++;
                }
            }
        }
        $fingerprint = array_map(static fn (array $r): array => [$r['row'], $r['action'], $r['login_id'], $r['status'], $r['plan'], $r['codes']], $rows);
        $digest = hash('sha256', $fileSha . '|' . ($parsed['encoding'] ?? '') . '|' . json_encode($fingerprint));
        return [
            'encoding' => $parsed['encoding'], 'encoding_requested' => $encoding, 'bom' => $parsed['bom'],
            'file_sha256' => $fileSha, 'digest' => $digest, 'columns' => $parsed['columns'],
            'file_errors' => $parsed['errors'], 'file_warnings' => $parsed['warnings'],
            'limits' => ['max_rows' => AccountCsvReader::MAX_ROWS, 'max_bytes' => AccountCsvReader::MAX_BYTES],
            'auth_types' => $this->authTypes->enabledTypes(),
            'summary' => $summary, 'rows' => $rows, 'parsed' => true,
        ];
    }

    /**
     * 행 검증. 한 행의 오류는 다른 행의 판정에 영향을 주지 않는다(파일 안 중복은 앞 행이 기준).
     *
     * @param list<array{row:int, cells:array<string,string>, extra:bool}> $records
     * @return list<array<string, mixed>>
     */
    private function validate(Actor $actor, array $records): array
    {
        // 기존 계정(대소문자 무시)과 이메일(경고용), 활성 로컬 관리자
        $existing = [];
        $emails = [];
        foreach ($this->db->all('SELECT id, login_id, role, status, auth_type, email FROM users') as $u) {
            $existing[strtolower((string) $u['login_id'])] = $u;
            if ($u['email'] !== null) {
                $emails[strtolower((string) $u['email'])] = true;
            }
        }
        $activeLocalAdmins = [];
        foreach ($existing as $key => $u) {
            if ($u['role'] === 'admin' && $u['status'] === 'active' && $u['auth_type'] === 'local') {
                $activeLocalAdmins[$key] = true;
            }
        }
        // 파일 안의 새 로그인 ID(표시 이름이 다른 사용자의 로그인 ID와 같은지 볼 때 함께 본다)
        $fileIds = [];
        foreach ($records as $rec) {
            $id = $rec['cells']['login_id'];
            if ($id !== '' && preg_match(self::LOGIN_ID_RE, $id) === 1) {
                $fileIds[strtolower($id)][] = $rec['row'];
            }
        }
        $seen = [];
        $fileEmails = [];
        $out = [];
        foreach ($records as $rec) {
            $c = $rec['cells'];
            $errors = [];
            $warnings = [];
            $action = strtolower($c['action']) === '' ? 'create' : strtolower($c['action']);
            $loginId = $c['login_id'];
            $key = strtolower($loginId);
            $r = ['row' => $rec['row'], 'action' => $action, 'login_id' => self::short($loginId), 'display_name' => null, 'email' => null,
                'role' => null, 'auth_type' => null, 'user_id' => null];
            if ($rec['extra']) {
                $errors[] = 'column_count';
            }
            if (!in_array($action, ['create', 'deactivate'], true)) {
                $errors[] = 'action_invalid';
                $r['action'] = self::short($c['action']);
            }
            if ($loginId === '') {
                $errors[] = 'login_id_required';
            } elseif (preg_match(self::LOGIN_ID_RE, $loginId) !== 1) {
                $errors[] = 'login_id_invalid';
            } elseif (isset($seen[$key])) {
                $errors[] = 'duplicate_in_file';
            }
            if ($loginId !== '' && preg_match(self::LOGIN_ID_RE, $loginId) === 1) {
                $seen[$key] = true;
            }
            if ($action === 'create') {
                if ($loginId !== '' && isset($existing[$key])) {
                    $errors[] = 'login_id_taken';
                }
                [$name, $nameReason] = UserProfileRules::displayName($c['display_name']);
                if ($nameReason !== null) {
                    $errors[] = 'display_name_' . $nameReason;
                } else {
                    $nameKey = strtolower((string) $name);
                    $otherInFile = array_diff($fileIds[$nameKey] ?? [], [$rec['row']]);
                    if ((isset($existing[$nameKey]) || $otherInFile !== []) && $nameKey !== $key) {
                        $errors[] = 'display_name_reserved';
                    }
                    $r['display_name'] = $name;
                }
                [$email, $emailReason] = UserProfileRules::email($c['email']);
                if ($emailReason !== null) {
                    $errors[] = 'email_' . $emailReason;
                } elseif ($email !== null) {
                    $r['email'] = $email;
                    if (isset($emails[strtolower($email)]) || isset($fileEmails[strtolower($email)])) {
                        $warnings[] = 'email_in_use';
                    }
                    $fileEmails[strtolower($email)] = true;
                }
                $role = strtolower($c['role']) === '' ? 'user' : strtolower($c['role']);
                if (!in_array($role, ['user', 'admin'], true)) {
                    $errors[] = 'role_invalid';
                } else {
                    $r['role'] = $role;
                    if ($role === 'admin') {
                        $warnings[] = 'role_admin';
                    }
                }
                $auth = strtolower($c['auth_type']) === '' ? $this->authTypes->defaultType() : strtolower($c['auth_type']);
                if (!$this->authTypes->isKnown($auth)) {
                    $errors[] = 'auth_type_invalid';
                } elseif (!$this->authTypes->isEnabled($auth)) {
                    $errors[] = 'auth_type_not_enabled';
                } else {
                    $r['auth_type'] = $auth;
                }
            } elseif ($action === 'deactivate') {
                foreach (['display_name', 'email', 'role', 'auth_type'] as $ignored) {
                    if ($c[$ignored] !== '') {
                        $warnings[] = 'fields_ignored';
                        break;
                    }
                }
                $target = $loginId === '' ? null : ($existing[$key] ?? null);
                if ($loginId !== '' && preg_match(self::LOGIN_ID_RE, $loginId) === 1 && $target === null) {
                    $errors[] = 'user_not_found';
                }
                if ($target !== null) {
                    $r['user_id'] = (int) $target['id'];
                    $r['login_id'] = (string) $target['login_id'];
                    $r['role'] = (string) $target['role'];
                    if ((int) $target['id'] === $actor->userId) {
                        $errors[] = 'self_deactivate';
                    } elseif ($target['status'] === 'inactive') {
                        $warnings[] = 'already_inactive';
                    } elseif (isset($activeLocalAdmins[$key]) && $errors === []) {
                        // 이 파일의 비활성화를 모두 반영해도 활성 로컬 관리자가 한 명은 남아야 한다(앞 행부터, 남는 마지막 관리자 행이 오류)
                        if (count($activeLocalAdmins) <= 1) {
                            $errors[] = 'last_admin';
                        } else {
                            unset($activeLocalAdmins[$key]);
                        }
                    }
                }
            }
            $status = $errors !== [] ? 'error' : ($warnings !== [] ? 'warning' : 'ok');
            $plan = 'none';
            if ($status !== 'error') {
                $plan = $action === 'create' ? 'create' : (in_array('already_inactive', $warnings, true) ? 'skip' : 'deactivate');
            }
            $out[] = $r + ['status' => $status, 'plan' => $plan, 'codes' => [...$errors, ...$warnings]];
        }
        return $out;
    }

    /** 첫 글자가 결과 CSV의 수식 문자가 아닌 임시 비밀번호(결과 파일의 값이 화면 값과 같게) */
    private function temporaryPassword(): string
    {
        $policy = $this->settings->passwordPolicy();
        do {
            $temp = $policy->generateTemporary();
        } while (in_array($temp[0], self::FORMULA_START, true));
        return $temp;
    }

    /** 응답에 되돌리는 사용자 입력은 짧게(표 표시용) */
    private static function short(string $s): string
    {
        return mb_strlen($s) > 100 ? mb_substr($s, 0, 100) . '…' : $s;
    }
}
