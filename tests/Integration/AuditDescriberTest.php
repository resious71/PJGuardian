<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Audit\AuditDescriber;
use PJGuardian\Services\Audit\AuditQuery;
use PJGuardian\Support\AuditActions;
use PJGuardian\Support\AuditChain;
use PJGuardian\Support\Lang;
use PJGuardian\Tests\Support\AuditSamples;
use PJGuardian\Tests\Support\CountingStatement;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\TestCase;

/**
 * 감사 로그 사람이 읽는 설명(X17, AuditDescriber).
 * - 누락 방지: AuditActions의 모든 작업에 ko·en 문장·작업 이름·분류 이름·대표 샘플(AuditSamples)이 있다 — 새 감사 작업을 더하고 설명을 빠뜨리면 실패.
 * - 샘플 문장이 기대 문장과 같다(ko·en), 세부 정보가 비었거나 값이 빠져도 깨지지 않는다(일반 문장, 자리표시자·조사 두 꼴이 남지 않음),
 *   삭제된 대상 "삭제된 항목(#id)", 알 수 없는 작업은 일반 문장 + known=false.
 * - 비밀 값(비밀번호·토큰·키·평문 이메일·40자 이상 문자열)이 문장·표·대상 이름·CSV 설명 열에 없다(가린 값을 풀지 않음).
 * - 이름 풀이 쿼리 수가 행 수와 무관하게 일정(10행 = 200행).
 * - 읽기 전용: 목록·상세 조회 전후 감사 행 내용과 체인 검증 결과가 같다.
 */
final class AuditDescriberTest extends TestCase
{
    private const KO = [
        'user.create' => '관리자 alex가 사용자 Jordan Lee (jordan)를 만들었습니다(역할 일반 사용자, 인증 방식 로컬).',
        'user.update' => '관리자 alex가 사용자 Jordan Lee (jordan)의 표시 이름, 이메일을 바꿨습니다.',
        'user.role_change' => '관리자 alex가 사용자 Jordan Lee (jordan)의 역할을 일반 사용자 → 관리자로 바꿨습니다.',
        'user.deactivate' => '관리자 alex가 사용자 Jordan Lee (jordan)를 비활성화했습니다.',
        'user.activate' => '관리자 alex가 사용자 Jordan Lee (jordan)를 다시 활성화했습니다.',
        'user.temp_password' => '관리자 alex가 사용자 Jordan Lee (jordan)에게 임시 비밀번호를 발급했습니다(만료 2026-10-03 12:00 KST).',
        'user.password_set' => '관리자 alex가 사용자 Jordan Lee (jordan)의 비밀번호를 새로 정했습니다.',
        'user.unlock' => '관리자 alex가 사용자 Jordan Lee (jordan)의 로그인 잠금을 풀었습니다.',
        'user.auth_type_change' => '관리자 alex가 사용자 Jordan Lee (jordan)의 인증 방식을 로컬 → LDAP/AD로 바꿨습니다.',
        'user.bulk_import' => '관리자 alex가 계정 CSV 일괄 처리를 적용했습니다(만듦 12, 비활성화 2, 건너뜀 1).',
        'user.pending_create' => 'SSO(OIDC) 첫 로그인으로 승인 대기 계정 Jordan Lee (jordan)가 만들어졌습니다.',
        'user.approve' => '관리자 alex가 승인 대기 계정 Jordan Lee (jordan)를 일반 사용자 역할로 승인했습니다.',
        'user.reject' => '관리자 alex가 승인 대기 계정 Jordan Lee (jordan)를 거절했습니다.',
        'user.pending_delete' => '관리자 alex가 승인 대기 계정 ghost를 삭제했습니다.',
        'user.pending_purge' => '시스템이 보관 기간(30일)이 지난 승인 대기 계정 4개를 정리했습니다.',
        'user.external_link' => '시스템이 기존 계정 Jordan Lee (jordan)를 LDAP/AD 로그인에 연결했습니다.',
        'cli.user_unlock' => '서버 명령줄 도구가 사용자 Jordan Lee (jordan)의 로그인 잠금을 풀었습니다.',
        'cli.user_password_reset' => '서버 명령줄 도구가 사용자 Jordan Lee (jordan)에게 임시 비밀번호를 발급했습니다(만료 2026-10-03 12:00 KST).',
        'auth.login_failed' => 'sam 계정의 LDAP/AD 로그인을 거부했습니다(로컬 계정은 외부 로그인 불가).',
        'auth.ldap_secret_set' => '관리자 alex가 LDAP/AD 검색 계정 비밀번호를 바꿨습니다.',
        'auth.ldap_secret_delete' => '관리자 alex가 LDAP/AD 검색 계정 비밀번호를 지웠습니다.',
        'auth.oidc_secret_set' => '관리자 alex가 SSO(OIDC) 클라이언트 비밀을 저장했습니다.',
        'auth.oidc_secret_delete' => '관리자 alex가 SSO(OIDC) 클라이언트 비밀을 지웠습니다.',
        'auth.saml_sp_key_generate' => '관리자 alex가 SSO(SAML) SP 키와 인증서를 만들었습니다.',
        'auth.saml_sp_key_delete' => '관리자 alex가 SSO(SAML) SP 키와 인증서를 지웠습니다.',
        'auth.saml_metadata_import' => '관리자 alex가 SSO(SAML) IdP 메타데이터를 가져왔습니다(주소에서, 인증서 2개).',
        'group.create' => '관리자 alex가 그룹 Platform을 만들었습니다.',
        'group.rename' => '관리자 alex가 그룹 이름을 Infra → Platform으로 바꿨습니다.',
        'group.update' => '관리자 alex가 그룹 Platform의 설명을 바꿨습니다.',
        'group.delete' => '관리자 alex가 그룹 Legacy를 삭제했습니다(구성원 3명, 연결된 프로젝트 1개).',
        'group.member_add' => '관리자 alex가 그룹 Platform에 jordan을 구성원으로 추가했습니다.',
        'group.member_remove' => '관리자 alex가 그룹 Platform에서 구성원 jordan을 뺐습니다.',
        'group.manager_set' => '관리자 alex가 jordan을 그룹 Platform의 그룹 관리자로 지정했습니다.',
        'group.manager_unset' => '관리자 alex가 그룹 Platform에서 jordan의 그룹 관리자 지정을 해제했습니다.',
        'project.create' => '관리자 alex가 프로젝트 Billing을 만들었습니다(언어 Python).',
        'project.update' => '관리자 alex가 프로젝트 Billing의 설명, 제외 패턴을 바꿨습니다.',
        'project.delete' => '관리자 alex가 프로젝트 Old app을 삭제했습니다(분석 14건, 결함 230건).',
        'project.access_add' => '관리자 alex가 프로젝트 Billing의 접근 권한에 그룹 Platform을 추가했습니다.',
        'project.access_remove' => '관리자 alex가 프로젝트 Billing의 접근 권한에서 사용자 jordan을 뺐습니다.',
        'run.detail_delete' => '시스템이 프로젝트 Billing의 Run 상세 2개를 지웠습니다(보관 기간 지남).',
        'repo.save' => '관리자 alex가 프로젝트 Billing에 Git 저장소 연결을 만들었습니다.',
        'repo.delete' => '관리자 alex가 프로젝트 Billing의 저장소 연결을 지웠습니다.',
        'repo.test' => '관리자 alex가 프로젝트 Billing의 저장소 연결을 테스트했습니다(실패: repo_auth_failed).',
        'repo.fetch_now' => '관리자 alex가 프로젝트 Billing의 저장소에서 지금 가져와 분석을 요청했습니다(분석 #42).',
        'repo.host_test' => '관리자 alex가 허용 호스트 git.example.com의 연결 테스트를 했습니다(성공).',
        'repo_host.add' => '관리자 alex가 허용 호스트 git.example.com을 추가했습니다(HTTP 허용 꺼짐, GitHub 링크 켜짐).',
        'repo_host.update' => '관리자 alex가 허용 호스트 git.example.com:8443의 설정을 바꿨습니다.',
        'repo_host.delete' => '관리자 alex가 허용 호스트 git.example.com을 삭제했습니다.',
        'schedule.save' => '관리자 alex가 프로젝트 Billing의 주기 분석 설정을 바꿨습니다.',
        'schedule.auto_disable' => '관리자 alex가 프로젝트 Billing의 주기 분석을 자동으로 껐습니다(저장소 연결 해제).',
        'rules.override_set' => '관리자 alex가 규칙 ruff:F401을 껐습니다.',
        'rules.override_clear' => '관리자 alex가 규칙 eslint:no-console의 변경을 지우고 기본값(켜짐)으로 되돌렸습니다.',
        'rules.override_reset_all' => '관리자 alex가 ruff 규칙 변경 3개를 모두 기본값으로 되돌렸습니다.',
        'rules.override_bulk' => '관리자 alex가 eslint 규칙 12개를 일괄로 껐습니다.',
        'rules.reload_request' => '관리자 alex가 규칙 설명 다시 불러오기를 요청했습니다.',
        'rules.load' => '서버 명령줄 도구가 규칙 설명 데이터를 적재했습니다(바뀐 규칙 5개, 오류 0건).',
        'export.defects' => '관리자 alex가 프로젝트 Billing의 결함 120건을 XLSX로 내보냈습니다.',
        'export.rules' => '관리자 alex가 규칙 목록 800건을 CSV로 내보냈습니다.',
        'tool.block' => '관리자 alex가 도구 버전 ruff@0.6.8을 차단했습니다.',
        'tool.unblock' => '관리자 alex가 도구 버전 ruff@0.6.8의 차단을 풀었습니다.',
        'tool.pin' => '관리자 alex가 도구 버전 eslint@10.11.0을 사용 버전으로 고정했습니다.',
        'tool.unpin' => '관리자 alex가 도구 eslint의 버전 고정을 풀었습니다.',
        'tool.mark_removal' => '관리자 alex가 도구 버전 ruff@0.5.0을 삭제 예정으로 표시했습니다.',
        'tool.unmark_removal' => '관리자 alex가 도구 버전 ruff@0.5.0의 삭제 예정 표시를 지웠습니다.',
        'tool.package_staged' => '관리자 alex가 ruff 도구 패키지를 올렸습니다(설치 대기 #3, 15.0 MB).',
        'tool.package_discarded' => '관리자 alex가 올린 ruff 도구 패키지를 폐기했습니다(설치 대기 #3).',
        'tool.package_hash_set' => '관리자 alex가 올린 도구 패키지의 공개 해시를 입력했습니다(설치 대기 #3, 일치 예).',
        'tool.reverify_request' => '관리자 alex가 도구 ruff@0.6.9의 다시 검증을 요청했습니다.',
        'worker.start_request' => '관리자 alex가 분석 워커 시작을 요청했습니다.',
        'worker.stop_request' => '관리자 alex가 분석 워커 정지를 요청했습니다(진행 중 분석 1건).',
        'worker.restart_request' => '관리자 alex가 분석 워커 재시작을 요청했습니다(진행 중 분석 0건).',
        'disk.scan_request' => '관리자 alex가 디스크 사용량 다시 계산을 요청했습니다.',
        'cleanup.run_request' => '관리자 alex가 정리 작업을 지금 실행하도록 요청했습니다.',
        'config.update' => '관리자 alex가 설정 실패한 업로드 zip 보관을 1d → 3d로 바꿨습니다.',
        'maintenance.on' => '관리자 alex가 업그레이드 유지보수 모드를 켰습니다(직전 백업 pjguardian-20261014-220000-pre_upgrade.pjgbak).',
        'maintenance.off' => '관리자 alex가 업그레이드 유지보수 모드를 껐습니다.',
        'setting.update' => '관리자 alex가 설정 읽은 알림 보관 기간을 7 → 14로 바꿨습니다.',
        'backup.create' => '관리자 alex의 수동 백업 PJGuardian_backup_20261002_210000_manual.zip이 만들어졌습니다(50.0 MB).',
        'backup.delete' => '관리자 alex가 백업 PJGuardian_backup_20260901_210000_manual.zip을 삭제했습니다.',
        'backup.download' => '관리자 alex가 백업 PJGuardian_backup_20261002_210000_manual.zip을 내려받았습니다(50.0 MB).',
        'backup.upload' => '관리자 alex가 백업 묶음 PJGuardian_backup_20260930_210000_daily.zip을 올렸습니다(40.0 MB).',
        'backup.verify' => '관리자 alex가 백업 PJGuardian_backup_20261002_210000_manual.zip을 검증했습니다(정상).',
        'restore.start' => '관리자 alex가 백업 PJGuardian_backup_20261002_210000_manual.zip으로 복원을 시작했습니다.',
        'restore.finish' => '복원 작업(alex)이 백업 PJGuardian_backup_20261002_210000_manual.zip의 복원을 마쳤습니다(지운 세션 5개).',
        'restore.rollback' => '복원 작업(alex)이 복원에 실패해 이전 상태로 되돌렸습니다(restore_io_failed).',
        'restore.rehearsal' => '관리자 alex가 백업 PJGuardian_backup_20261002_210000_manual.zip으로 복원 리허설을 했습니다(실패: bundle_hash_mismatch).',
        'key.export' => '관리자 alex가 자격 증명 암호화 키를 내보냈습니다(키 지문 SHA256:ab12cd34).',
        'key.import' => '관리자 alex가 백업 PJGuardian_backup_20261002_210000_manual.zip의 자격 증명 암호화 키를 가져왔습니다(키 지문 SHA256:ab12cd34).',
        'faq.create' => '관리자 alex가 FAQ 항목을 공개로 추가했습니다: 비밀번호를 잊었어요',
        'faq.update' => '관리자 alex가 FAQ 항목의 답변을 고쳤습니다: 비밀번호를 잊었어요',
        'faq.publish' => '관리자 alex가 FAQ 항목을 비공개로 바꿨습니다: 비밀번호를 잊었어요',
        'faq.delete' => '관리자 alex가 FAQ 항목을 삭제했습니다: 예전 질문',
        'faq.reorder' => '관리자 alex가 FAQ 분류 계정의 항목 순서를 바꿨습니다.',
        'faq.import' => '서버 명령줄 도구가 FAQ 시드를 가져왔습니다(추가 35개, 건너뜀 0개).',
        'audit.export' => '관리자 alex가 감사 로그 250건을 CSV로 내보냈습니다.',
        'audit.purge' => '시스템이 보존 기간이 지난 감사 로그 1,200건을 정리했습니다.',
        'mail.secret_set' => '관리자 alex가 관리자 이메일 SMTP 비밀번호를 저장했습니다.',
        'mail.secret_delete' => '관리자 alex가 관리자 이메일 SMTP 비밀번호를 지웠습니다.',
        'mail.test' => '관리자 alex가 시험 메일을 o***@example.com에게 보내려 했지만 실패했습니다(auth).',
    ];

    private const EN = [
        'user.create' => 'Admin alex created user Jordan Lee (jordan) with role Regular user and sign-in method Local.',
        'user.update' => 'Admin alex changed display name, email of user Jordan Lee (jordan).',
        'user.role_change' => 'Admin alex changed the role of user Jordan Lee (jordan) from Regular user to Admin.',
        'user.deactivate' => 'Admin alex deactivated user Jordan Lee (jordan).',
        'user.activate' => 'Admin alex reactivated user Jordan Lee (jordan).',
        'user.temp_password' => 'Admin alex issued a temporary password to user Jordan Lee (jordan) (expires 2026-10-03 12:00 KST).',
        'user.password_set' => 'Admin alex set a new password for user Jordan Lee (jordan).',
        'user.unlock' => 'Admin alex unlocked user Jordan Lee (jordan).',
        'user.auth_type_change' => 'Admin alex changed the sign-in method of user Jordan Lee (jordan) from Local to LDAP/AD.',
        'user.bulk_import' => 'Admin alex applied an account CSV import (created 12, deactivated 2, skipped 1).',
        'user.pending_create' => 'A first SSO (OIDC) sign-in created pending account Jordan Lee (jordan).',
        'user.approve' => 'Admin alex approved pending account Jordan Lee (jordan) with role Regular user.',
        'user.reject' => 'Admin alex rejected pending account Jordan Lee (jordan).',
        'user.pending_delete' => 'Admin alex deleted pending account ghost.',
        'user.pending_purge' => 'System removed 4 pending accounts older than the retention period (30 days).',
        'user.external_link' => 'System linked existing account Jordan Lee (jordan) to LDAP/AD sign-in.',
        'cli.user_unlock' => 'Server CLI unlocked user Jordan Lee (jordan).',
        'cli.user_password_reset' => 'Server CLI issued a temporary password to user Jordan Lee (jordan) (expires 2026-10-03 12:00 KST).',
        'auth.login_failed' => 'LDAP/AD sign-in for account sam was refused (local accounts cannot use external sign-in).',
        'auth.ldap_secret_set' => 'Admin alex replaced the LDAP/AD bind account password.',
        'auth.ldap_secret_delete' => 'Admin alex removed the LDAP/AD bind account password.',
        'auth.oidc_secret_set' => 'Admin alex saved the SSO (OIDC) client secret.',
        'auth.oidc_secret_delete' => 'Admin alex removed the SSO (OIDC) client secret.',
        'auth.saml_sp_key_generate' => 'Admin alex generated the SSO (SAML) SP key and certificate.',
        'auth.saml_sp_key_delete' => 'Admin alex removed the SSO (SAML) SP key and certificate.',
        'auth.saml_metadata_import' => 'Admin alex imported SSO (SAML) IdP metadata (from URL, 2 certificates).',
        'group.create' => 'Admin alex created group Platform.',
        'group.rename' => 'Admin alex renamed group Infra to Platform.',
        'group.update' => 'Admin alex changed description of group Platform.',
        'group.delete' => 'Admin alex deleted group Legacy (3 members, 1 linked project).',
        'group.member_add' => 'Admin alex added jordan to group Platform.',
        'group.member_remove' => 'Admin alex removed jordan from group Platform.',
        'group.manager_set' => 'Admin alex made jordan a group manager of Platform.',
        'group.manager_unset' => 'Admin alex removed jordan as a group manager of Platform.',
        'project.create' => 'Admin alex created project Billing (language Python).',
        'project.update' => 'Admin alex changed description, exclude patterns of project Billing.',
        'project.delete' => 'Admin alex deleted project Old app (14 analyses, 230 defects).',
        'project.access_add' => 'Admin alex gave group Platform access to project Billing.',
        'project.access_remove' => 'Admin alex removed access to project Billing from user jordan.',
        'run.detail_delete' => 'System deleted 2 Run details of project Billing (retention period passed).',
        'repo.save' => 'Admin alex created a Git repository connection for project Billing.',
        'repo.delete' => 'Admin alex removed the repository connection of project Billing.',
        'repo.test' => 'Admin alex ran a connection test for project Billing (failed: repo_auth_failed).',
        'repo.fetch_now' => 'Admin alex requested fetch and analyze now for project Billing (analysis #42).',
        'repo.host_test' => 'Admin alex ran a connection test for allowed host git.example.com (succeeded).',
        'repo_host.add' => 'Admin alex added allowed host git.example.com (allow HTTP Off, GitHub links On).',
        'repo_host.update' => 'Admin alex changed the settings of allowed host git.example.com:8443.',
        'repo_host.delete' => 'Admin alex deleted allowed host git.example.com.',
        'schedule.save' => 'Admin alex changed the scheduled analysis of project Billing.',
        'schedule.auto_disable' => 'Admin alex turned off the scheduled analysis of project Billing automatically (repository connection removed).',
        'rules.override_set' => 'Admin alex turned off rule ruff:F401.',
        'rules.override_clear' => 'Admin alex reset rule eslint:no-console to its default (On).',
        'rules.override_reset_all' => 'Admin alex reset all 3 ruff rule changes to defaults.',
        'rules.override_bulk' => 'Admin alex turned off 12 eslint rules in bulk.',
        'rules.reload_request' => 'Admin alex requested a reload of the rule descriptions.',
        'rules.load' => 'Server CLI loaded rule description data (5 changed rules, 0 errors).',
        'export.defects' => 'Admin alex exported 120 defects of project Billing to XLSX.',
        'export.rules' => 'Admin alex exported 800 rules to CSV.',
        'tool.block' => 'Admin alex blocked tool version ruff@0.6.8.',
        'tool.unblock' => 'Admin alex unblocked tool version ruff@0.6.8.',
        'tool.pin' => 'Admin alex pinned tool version eslint@10.11.0.',
        'tool.unpin' => 'Admin alex unpinned tool eslint.',
        'tool.mark_removal' => 'Admin alex marked tool version ruff@0.5.0 for removal.',
        'tool.unmark_removal' => 'Admin alex cleared the removal mark of tool version ruff@0.5.0.',
        'tool.package_staged' => 'Admin alex uploaded a ruff tool package (staging #3, 15.0 MB).',
        'tool.package_discarded' => 'Admin alex discarded an uploaded ruff tool package (staging #3).',
        'tool.package_hash_set' => 'Admin alex entered the published hash of an uploaded tool package (staging #3, match Yes).',
        'tool.reverify_request' => 'Admin alex requested re-verification of tool ruff@0.6.9.',
        'worker.start_request' => 'Admin alex requested a worker start.',
        'worker.stop_request' => 'Admin alex requested a worker stop (1 running analysis).',
        'worker.restart_request' => 'Admin alex requested a worker restart (0 running analyses).',
        'disk.scan_request' => 'Admin alex requested a disk usage recount.',
        'cleanup.run_request' => 'Admin alex requested the cleanup task to run now.',
        'config.update' => 'Admin alex changed setting Retention of failed upload zips from 1d to 3d.',
        'maintenance.on' => 'Admin alex turned on upgrade maintenance mode (backup before it: pjguardian-20261014-220000-pre_upgrade.pjgbak).',
        'maintenance.off' => 'Admin alex turned off upgrade maintenance mode.',
        'setting.update' => 'Admin alex changed setting Read notification retention from 7 to 14.',
        'backup.create' => 'Manual backup PJGuardian_backup_20261002_210000_manual.zip by Admin alex was created (50.0 MB).',
        'backup.delete' => 'Admin alex deleted backup PJGuardian_backup_20260901_210000_manual.zip.',
        'backup.download' => 'Admin alex downloaded backup PJGuardian_backup_20261002_210000_manual.zip (50.0 MB).',
        'backup.upload' => 'Admin alex uploaded backup bundle PJGuardian_backup_20260930_210000_daily.zip (40.0 MB).',
        'backup.verify' => 'Admin alex verified backup PJGuardian_backup_20261002_210000_manual.zip (OK).',
        'restore.start' => 'Admin alex started a restore from backup PJGuardian_backup_20261002_210000_manual.zip.',
        'restore.finish' => 'Restore job (alex) finished the restore from backup PJGuardian_backup_20261002_210000_manual.zip (5 sessions cleared).',
        'restore.rollback' => 'Restore job (alex) rolled back to the previous state after a failed restore (restore_io_failed).',
        'restore.rehearsal' => 'Admin alex ran a restore rehearsal with backup PJGuardian_backup_20261002_210000_manual.zip (failed: bundle_hash_mismatch).',
        'key.export' => 'Admin alex exported the credentials encryption key (key fingerprint SHA256:ab12cd34).',
        'key.import' => 'Admin alex imported the credentials encryption key for backup PJGuardian_backup_20261002_210000_manual.zip (key fingerprint SHA256:ab12cd34).',
        'faq.create' => 'Admin alex added a published FAQ entry: 비밀번호를 잊었어요',
        'faq.update' => 'Admin alex edited answer of an FAQ entry: 비밀번호를 잊었어요',
        'faq.publish' => 'Admin alex unpublished an FAQ entry: 비밀번호를 잊었어요',
        'faq.delete' => 'Admin alex deleted an FAQ entry: 예전 질문',
        'faq.reorder' => 'Admin alex reordered the entries of FAQ category 계정.',
        'faq.import' => 'Server CLI imported the FAQ seed (added 35, skipped 0).',
        'audit.export' => 'Admin alex exported 250 audit log entries to CSV.',
        'audit.purge' => 'System removed 1,200 audit log entries older than the retention period.',
        'mail.secret_set' => 'Admin alex saved the admin email SMTP password.',
        'mail.secret_delete' => 'Admin alex removed the admin email SMTP password.',
        'mail.test' => 'Admin alex tried to send a test email to o***@example.com, but it failed (auth).',
    ];

    private App $app;

    public function setUp(): void
    {
        $dir = $this->tempDir('auditdesc');
        $this->app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work'], 'app' => ['display_timezone' => 'Asia/Seoul']]);
        AuditSamples::fixture($this->app->db());
    }

    public function tearDown(): void
    {
        Lang::setLocale('ko');
    }

    /** @return list<array<string, mixed>> AuditQuery 한 행 모양(세부 정보는 화면 단계 마스킹 후) */
    private static function sampleRows(): array
    {
        $rows = [];
        $i = 0;
        foreach (AuditSamples::ROWS as $action => [$actor, $type, $id, $d]) {
            $rows[] = self::row(++$i, $action, $actor, $type, $id, $d);
        }
        return $rows;
    }

    private static function row(int $id, string $action, string $actor, ?string $type, ?string $target, array $details): array
    {
        return ['id' => $id, 'occurred_at' => '2026-10-02T12:00:00Z', 'actor' => $actor, 'actor_user_id' => $actor === 'alex' ? 1 : null, 'action' => $action,
            'target_type' => $type, 'target_id' => $target, 'ip' => $actor === 'alex' ? '10.0.0.1' : null, 'details' => AuditQuery::safe($details)];
    }

    private function describer(): AuditDescriber
    {
        return new AuditDescriber($this->app->db(), 'Asia/Seoul');
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    public function testEveryActionHasTemplatesAndSample(): void
    {
        $ko = Lang::load('ko');
        $en = Lang::load('en');
        $missing = [];
        foreach (AuditActions::all() as $a) {
            foreach (["audit.desc.{$a}", "audit.act.{$a}"] as $k) {
                if (!isset($ko[$k])) {
                    $missing[] = "ko {$k}";
                }
                if (!isset($en[$k])) {
                    $missing[] = "en {$k}";
                }
            }
            if (!isset(AuditSamples::ROWS[$a])) {
                $missing[] = "sample {$a}";
            }
            if (!isset(self::KO[$a]) || !isset(self::EN[$a])) {
                $missing[] = "expected {$a}";
            }
        }
        foreach (AuditActions::prefixes() as $p) {
            if (!isset($ko["audit.group.{$p}"], $en["audit.group.{$p}"])) {
                $missing[] = "group {$p}";
            }
        }
        $this->assertSame([], $missing, '새 감사 작업은 문장(audit.desc)·작업 이름(audit.act)·분류(audit.group)·샘플·기대 문장을 함께 더한다');
        $this->assertSame([], array_values(array_diff(array_keys(AuditSamples::ROWS), AuditActions::all())), '목록에 없는 작업의 샘플');
        // 변형 문장(audit.desc.<작업>.<변형>)은 있는 작업의 것만, ko·en 함께
        foreach (array_keys($ko) as $k) {
            if (str_starts_with($k, 'audit.desc.') && !str_starts_with($k, 'audit.desc._')) {
                $rest = substr($k, 11);
                $this->assertTrue(in_array($rest, AuditActions::all(), true) || in_array(substr($rest, 0, (int) strrpos($rest, '.')), AuditActions::all(), true), $k);
                $this->assertTrue(isset($en[$k]), "en {$k}");
            }
        }
    }

    public function testSampleSentencesKoEn(): void
    {
        foreach (['ko' => self::KO, 'en' => self::EN] as $loc => $expected) {
            Lang::setLocale($loc);
            $got = [];
            foreach ($this->describer()->describeMany(self::sampleRows()) as $r) {
                $this->assertTrue($r['known'], $r['action']);
                $got[$r['action']] = $r['summary'];
            }
            foreach ($expected as $a => $s) {
                $this->assertSame($s, $got[$a] ?? null, "{$loc} {$a}");
            }
            if ($loc === 'en') {
                foreach ($got as $a => $s) {
                    // 사용자 입력(FAQ 질문·분류 이름)만 한글일 수 있다
                    if (!str_starts_with($a, 'faq.')) {
                        $this->assertSame(0, preg_match('/\p{Hangul}/u', $s), "en {$a}: {$s}");
                    }
                }
            }
        }
    }

    public function testFieldsTableUsesHumanLabelsAndValues(): void
    {
        $by = [];
        foreach ($this->describer()->describeMany(self::sampleRows()) as $r) {
            $by[$r['action']] = $r;
        }
        $role = $by['user.role_change']['fields'];
        $this->assertSame([['key' => 'role', 'label' => '역할', 'before' => '일반 사용자', 'after' => '관리자', 'changed' => true]], $role);
        $host = array_column($by['repo_host.update']['fields'], null, 'key');
        $this->assertSame(['HTTP 허용', '꺼짐', '켜짐'], [$host['allow_http']['label'], $host['allow_http']['before'], $host['allow_http']['after']]);
        $sched = array_column($by['schedule.save']['fields'], null, 'key');
        $this->assertSame(['7', '1'], [$sched['interval_days']['before'], $sched['interval_days']['after']]);
        $this->assertSame('2026-10-04 03:00 KST', $sched['next_run_at']['after'], '시각은 화면 시간대');
        $cfg = $by['config.update'];
        $this->assertSame(['1d', '3d', true], [$cfg['fields'][0]['before'], $cfg['fields'][0]['after'], $cfg['fields'][0]['changed']]);
        $this->assertSame('/admin/config-settings', $cfg['target_href']);
        $grp = $by['group.update']['fields'][0];
        $this->assertSame(['설명', '(없음)', '플랫폼 팀'], [$grp['label'], $grp['before'], $grp['after']], '빈 값은 (없음)');
        $this->assertSame('(가려진 값)', array_column($by['restore.finish']['fields'], 'after', 'key')['credentials_unreadable']);
        $this->assertSame('15.0 MB', array_column($by['tool.package_staged']['fields'], 'after', 'key')['size']);
        // 대상 이름·링크·출처·결과
        $this->assertSame(['Jordan Lee (jordan)', '/admin/users'], [$by['user.unlock']['target_label'], $by['user.unlock']['target_href']]);
        $this->assertSame(['Billing', '/projects/1'], [$by['project.update']['target_label'], $by['project.update']['target_href']]);
        $this->assertSame(['Platform', '/groups/1'], [$by['group.create']['target_label'], $by['group.create']['target_href']]);
        $this->assertSame(['비밀번호를 잊었어요', '/guide/faq#faq-1'], [$by['faq.publish']['target_label'], $by['faq.publish']['target_href']]);
        $this->assertSame('/rules/ruff/F401', $by['rules.override_set']['target_href']);
        $this->assertSame(['web', 'cli', 'system', 'login'], [$by['user.unlock']['source'], $by['cli.user_unlock']['source'], $by['audit.purge']['source'], $by['auth.login_failed']['source']]);
        $this->assertSame(['Alex Kim', 'alex'], [$by['user.unlock']['actor_name'], $by['user.unlock']['actor_display']]);
        $this->assertSame('failure', $by['repo.test']['outcome']);
        $this->assertSame('repo_auth_failed', $by['repo.test']['outcome_code']);
        $this->assertSame('/guide/errors#repo_auth_failed', $by['repo.test']['outcome_href'], '가이드 오류 코드 탭에 있는 코드는 링크');
        $this->assertSame(Lang::get('backup.error.restore_io_failed'), $by['restore.rollback']['outcome_reason'], '사유 코드 풀이 문구');
        $this->assertSame(['bundle_hash_mismatch', null], [$by['restore.rehearsal']['outcome_reason'], $by['restore.rehearsal']['outcome_href']], '자리표시자가 남는 문구는 쓰지 않고 코드 그대로');
        $this->assertSame('failure', $by['auth.login_failed']['outcome']);
        $this->assertSame('success', $by['backup.verify']['outcome']);
        $this->assertSame('success', $by['repo.host_test']['outcome']);
        $this->assertSame('사용자', $by['user.create']['action_group']);
        $this->assertSame('역할 변경', $by['user.role_change']['action_label']);
    }

    public function testMissingOrEmptyDetailsNeverBreak(): void
    {
        foreach (['ko', 'en'] as $loc) {
            Lang::setLocale($loc);
            $rows = [];
            $i = 0;
            foreach (AuditSamples::ROWS as $action => [$actor, $type, $id, $d]) {
                $rows[] = self::row(++$i, $action, $actor, null, null, []);                                            // 세부 정보·대상 없음
                $rows[] = self::row(++$i, $action, $actor, $type, $id, array_map(static fn () => null, $d));          // 값이 모두 null
                $rows[] = self::row(++$i, $action, $actor, $type, $id, array_map(static fn () => '', $d));            // 값이 모두 빈 글자
                $rows[] = self::row(++$i, $action, $actor, $type, $id, array_map(static fn () => ['x' => [1, 2]], $d)); // 모양이 다른 값
            }
            foreach ($this->describer()->describeMany($rows) as $r) {
                $s = $r['summary'];
                $this->assertTrue($s !== '', "{$loc} {$r['action']} 빈 문장");
                $this->assertSame(0, preg_match('/\{\w+(?::[^{}]*)?\}/u', $s), "{$loc} {$r['action']} 자리표시자 남음: {$s}");
                $this->assertSame(0, preg_match('/\(를\)|\(가\)|\(는\)|\(과\)|\(으\)로/u', $s), "{$loc} {$r['action']} 조사 두 꼴 남음: {$s}");
                $this->assertTrue($r['known'], $r['action']);
                foreach ($r['fields'] as $f) {
                    $this->assertTrue(is_string($f['label']) && is_string($f['after']), $r['action']);
                }
            }
        }
        Lang::setLocale('ko');
        $r = $this->describer()->describeMany([self::row(1, 'user.role_change', 'alex', 'user', '2', [])])[0];
        $this->assertSame('관리자 alex가 "역할 변경" 작업을 했습니다.', $r['summary'], '값이 빠진 옛 행은 작업 이름만 쓴 일반 문장');
    }

    public function testDeletedTargetAndUnknownAction(): void
    {
        $rows = [
            self::row(1, 'project.update', 'alex', 'project', '999', ['description' => ['from' => 'a', 'to' => 'b']]),
            self::row(2, 'group.member_add', 'alex', 'group', '55', ['user_id' => 2, 'login_id' => 'jordan']),
            self::row(3, 'faq.publish', 'alex', 'faq', '77', ['published' => true]),
            self::row(4, 'user.unlock', 'alex', 'user', '404', []),
            self::row(5, 'future.thing', 'alex', 'widget', '3', ['size' => 10, 'note' => '메모']),
            self::row(6, 'project.delete', 'alex', 'project', '5', ['name' => 'Old app', 'analyses' => 1, 'defects' => 2]),
        ];
        [$p, $g, $f, $u, $x, $del] = $this->describer()->describeMany($rows);
        $this->assertSame('관리자 alex가 프로젝트 삭제된 항목(#999)의 설명을 바꿨습니다.', $p['summary']);
        $this->assertSame(['삭제된 항목(#999)', null, true], [$p['target_label'], $p['target_href'], $p['target_deleted']]);
        $this->assertSame('삭제된 항목(#55)', $g['target_label']);
        $this->assertSame('관리자 alex가 FAQ 항목을 공개했습니다: 삭제된 항목(#77)', $f['summary']);
        $this->assertSame(['삭제된 항목(#404)', null], [$u['target_label'], $u['target_href']]);
        $this->assertSame(['Old app', null, true], [$del['target_label'], $del['target_href'], $del['target_deleted']], '지운 대상은 기록 때 남긴 이름(링크 없음)');
        // 알 수 없는 작업: 일반 문장 + known=false, 원본 키는 그대로 표에
        $this->assertSame('작업 future.thing이 기록되었습니다.', $x['summary']);
        $this->assertSame(false, $x['known']);
        $this->assertSame('future.thing', $x['action_label']);
        $this->assertSame(['size', 'note'], array_column($x['fields'], 'key'));
        $this->assertSame('widget:3', $x['target_label']);
        Lang::setLocale('en');
        [$p2, , , , $x2] = $this->describer()->describeMany($rows);
        $this->assertSame('Admin alex changed description of project Deleted item (#999).', $p2['summary']);
        $this->assertSame('Action future.thing was recorded.', $x2['summary']);
    }

    public function testNoSecretsInDescriptionsTablesOrCsv(): void
    {
        $secrets = ['S3cret!passw0rd', 'tok_live_abcdefghijklmnopqrstuvwxyz0123456789', 'AKIAABCDEFGHIJKLMNOP', 'smtp-pass-123', 'jordan@example.com', 'boss@corp.example'];
        $audit = $this->app->audit();
        $alex = ['id' => 1, 'login_id' => 'alex'];
        $audit->record(Actor::fromUser($alex, '10.0.0.1'), 'user.update', 'user', '2', [
            'actor_role' => 'admin', 'self' => false, 'email' => ['from' => 'jordan@example.com', 'to' => 'boss@corp.example'],
            'password' => 'S3cret!passw0rd', 'token' => 'tok_live_abcdefghijklmnopqrstuvwxyz0123456789',
        ]);
        $audit->record(Actor::fromUser($alex, '10.0.0.1'), 'config.update', 'config', 'notify.smtp_password', ['old' => 'smtp-pass-123', 'new' => 'boss@corp.example', 'old_source' => 'config']);
        $audit->record(Actor::fromUser($alex, '10.0.0.1'), 'future.secret', 'user', '2', ['api_key' => 'AKIAABCDEFGHIJKLMNOP', 'note' => 'ask jordan@example.com, secret=S3cret!passw0rd']);
        $audit->record(Actor::fromUser($alex, '10.0.0.1'), 'repo.save', 'project', '1', ['kind' => 'git', 'created' => false, 'fields' => ['username'], 'auth_change' => 'replaced',
            'note' => str_repeat('A1b2C3d4', 8)]);
        $c = $this->as('alex');
        $list = json_decode($c->get('/api/v1/admin/audit-logs')->body, true);
        $texts = [];
        foreach ($list['data'] as $r) {
            $this->assertTrue(isset($r['summary'], $r['fields'], $r['target_label']), 'API 목록에 summary·fields·target_label');
            $this->assertTrue(!isset($r['details']), '목록에는 원본 세부 정보 없음');
            $one = json_decode($c->get('/api/v1/admin/audit-logs/' . $r['id'])->body, true)['data'];
            $texts[] = $one['summary'];
            $texts[] = $one['target_label'];
            foreach ($one['fields'] as $f) {
                $texts[] = $f['label'] . ' ' . ($f['before'] ?? '') . ' ' . $f['after'];
            }
            $texts[] = (string) json_encode($one['details'], JSON_UNESCAPED_UNICODE);
        }
        $csv = $c->request('POST', '/api/v1/admin/audit-logs/export', [], []);
        $this->assertSame(200, $csv->status, $csv->body);
        $body = (string) file_get_contents($csv->filePath());
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $body))));
        $this->assertTrue(str_ends_with($lines[0], ',설명'), 'CSV 마지막 열 "설명": ' . $lines[0]);
        $all = implode("\n", $texts) . "\n" . $body;
        foreach ($secrets as $s) {
            $this->assertNotContains($s, $all, '비밀 값 노출');
        }
        foreach ($texts as $t) {
            if (str_starts_with($t, '{')) {
                continue; // 원본 JSON(기술 정보)은 가려진 그대로 — 위의 비밀 값 검사만
            }
            $this->assertSame(0, preg_match('/[A-Za-z0-9+\/=_\-]{40,}/', $t), "40자 이상 문자열: {$t}");
            $this->assertSame(0, preg_match('/[A-Za-z0-9._%+-]{2,}@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $t), "평문 이메일: {$t}");
        }
        $this->assertContains('관리자 alex가 사용자 Jordan Lee (jordan)의 이메일을 바꿨습니다.', $body, 'CSV 설명 열');
    }

    public function testNameLookupQueryCountIsConstant(): void
    {
        $db = $this->app->db();
        $t = '2026-10-01T00:00:00Z';
        for ($i = 0; $i < 60; $i++) {
            $db->execute('INSERT INTO projects (name, language, created_at, updated_at) VALUES (?, ?, ?, ?)', ["p{$i}", 'python', $t, $t]);
            $db->execute('INSERT INTO groups (name, created_at, updated_at) VALUES (?, ?, ?)', ["g{$i}", $t, $t]);
        }
        $make = static function (int $n): array {
            $rows = [];
            $kinds = [['project.update', 'project'], ['group.create', 'group'], ['user.unlock', 'user'], ['faq.publish', 'faq'], ['config.update', 'config']];
            for ($i = 1; $i <= $n; $i++) {
                [$a, $type] = $kinds[$i % count($kinds)];
                $r = self::row($i, $a, $i % 3 === 0 ? 'system' : 'alex', $type, $type === 'config' ? 'upload.failed_zip_retention' : (string) ($i % 70 + 1), ['name' => 'x']);
                $r['actor_user_id'] = $i % 3 === 0 ? null : ($i % 2) + 1;
                $rows[] = $r;
            }
            return $rows;
        };
        $pdo = $db->pdo();
        $old = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, []]);
        try {
            $d = $this->describer();
            $d->describeMany($make(5)); // 처음 한 번(029 확인 등 캐시)
            $counts = [];
            foreach ([10, 200] as $n) {
                CountingStatement::$count = 0;
                $out = $d->describeMany($make($n));
                $counts[$n] = CountingStatement::$count;
                $this->assertSame($n, count($out));
            }
        } finally {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $old);
        }
        $this->assertSame($counts[10], $counts[200], '이름 풀이 쿼리 수는 행 수와 무관(N+1 없음): ' . json_encode($counts));
        $this->assertTrue($counts[200] <= 4, '종류마다 한 번(사용자·프로젝트·그룹·FAQ): ' . $counts[200]);
        // 화면 API 한 쪽(200행)도 같은 상한: 목록 COUNT + 목록 SELECT + 이름 풀이 4 (+ 기간 조건 없음)
        foreach ($make(200) as $r) {
            $this->app->audit()->record(Actor::labeled($r['actor']), $r['action'], $r['target_type'], $r['target_id'], []);
        }
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, []]);
        try {
            CountingStatement::$count = 0;
            [$items] = $this->app->auditQuery()->list($this->app->auditQuery()->filter([]), 1, 200);
            $n = CountingStatement::$count;
        } finally {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $old);
        }
        $this->assertSame(200, count($items));
        $this->assertTrue($n <= 7, "목록 200행 쿼리 수 {$n}");
    }

    public function testViewingDoesNotChangeRowsOrChain(): void
    {
        $i = 0;
        foreach (AuditSamples::ROWS as $action => [$actor, $type, $id, $d]) {
            $this->app->audit()->record($actor === 'alex' ? Actor::fromUser(['id' => 1, 'login_id' => 'alex'], '10.0.0.1') : Actor::labeled($actor), $action, $type, $id, $d);
            $i++;
        }
        $db = $this->app->db();
        $snap = static fn (): string => hash('sha256', (string) json_encode($db->all('SELECT * FROM audit_log ORDER BY id')));
        $before = $snap();
        $verifyBefore = AuditChain::verify($db);
        $c = $this->as('alex');
        foreach (['ko', 'en'] as $lang) {
            $page = 1;
            do {
                $r = json_decode($c->get('/api/v1/admin/audit-logs', ['page' => $page, 'size' => 50, 'lang' => $lang])->body, true);
                foreach ($r['data'] as $row) {
                    $this->assertSame(200, $c->get('/api/v1/admin/audit-logs/' . $row['id'])->status);
                }
                $page++;
            } while ($page <= $r['page']['pages']);
        }
        $this->assertSame($before, $snap(), '조회는 감사 행을 바꾸지 않는다');
        $this->assertSame($verifyBefore, AuditChain::verify($db));
        $this->assertTrue($verifyBefore['ok']);
        $this->assertSame($i, (int) $db->value('SELECT COUNT(*) FROM audit_log'));
    }

    public function testFilterOptionsShowHumanNames(): void
    {
        $page = $this->as('alex')->get('/admin/audit-logs');
        $this->assertSame(200, $page->status);
        $this->assertContains('사용자 — 역할 변경 (user.role_change)', $page->body);
        $this->assertContains('<optgroup label="백업">', $page->body);
        $this->assertContains('백업 전체 (backup.*)', $page->body);
        foreach (AuditActions::all() as $a) {
            $this->assertContains('value="' . $a . '"', $page->body, $a);
        }
    }
}
