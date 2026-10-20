<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Repositories\GroupRepository;
use PJGuardian\Support\Db;

/**
 * 그룹 권한 판정(명세 3.1). **그룹 관련 권한은 이 클래스에서만 판정한다**: 서비스·API·화면·메뉴 노출이 모두 이것을 쓴다.
 *
 * - admin(users.role = 'admin')은 모든 그룹의 관리자로 간주한다(구성원 행 없음).
 * - 그룹 관리자는 group_members.is_manager = 1인 구성원. 그룹별 지정이며 시스템 역할이 아니다.
 * - 비활성 사용자는 아무것도 할 수 없다(로그인도 막힌다). 비로그인(null)도 마찬가지.
 * - 판정은 매번 DB를 읽는다(세션에 캐시하지 않음): 구성원·관리자 변경이 다음 요청부터 바로 반영된다.
 *
 * @phpstan-type User array{id:int, role:string, status:string}
 */
final class GroupPermission
{
    private readonly GroupRepository $repo;

    public function __construct(Db $db)
    {
        $this->repo = new GroupRepository($db);
    }

    /** @param array<string, mixed>|null $user */
    private static function active(?array $user): bool
    {
        return $user !== null && ($user['status'] ?? 'active') === 'active' && isset($user['id']);
    }

    /** 관리자 전용 작업(그룹 생성·이름 변경·삭제, 이후 프로젝트 매핑) */
    public function canAdministrate(?array $user): bool
    {
        return self::active($user) && ($user['role'] ?? '') === 'admin';
    }

    /** 이 그룹을 관리할 수 있는가(구성원 보기·추가·제거, 관리자 지정·해제, 계정 생성): admin 또는 그 그룹의 관리자 */
    public function canManage(?array $user, int $groupId): bool
    {
        if ($this->canAdministrate($user)) {
            return true;
        }
        return self::active($user) && $this->repo->isManager($groupId, (int) $user['id']);
    }

    /** 어느 그룹이든 관리하는가(그룹 메뉴 노출, 그룹 목록, 사용자 검색): admin 또는 한 그룹 이상의 관리자 */
    public function managesAny(?array $user): bool
    {
        if ($this->canAdministrate($user)) {
            return true;
        }
        return self::active($user) && $this->repo->managesAny((int) $user['id']);
    }
}
