<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\ApiException;
use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\ProjectAccess;

/**
 * 프로젝트 접근 판정(명세 3.1). 프로젝트(또는 그 자식 리소스)를 다루는 라우트는 반드시 이 미들웨어를 붙인다
 * (tests/Integration/ProjectRouteAccessTest가 검사). 판정 자체는 ProjectAccess::requireView 한 곳.
 *
 * - 순서: RequireLogin → RequirePasswordChanged → **RequireProjectAccess** → RequireAdmin.
 *   접근 검사가 권한 검사보다 먼저다: 보이지 않는 프로젝트는 수정·삭제 요청도 404, 보이지만 권한이 없으면 403.
 * - 프로젝트 id 경로: new RequireProjectAccess($access)                       ({id}가 프로젝트 id)
 * - 자식 리소스 경로: new RequireProjectAccess($access, 'id', $resolver, 'analysis_not_found')
 *   $resolver(string $childId): ?int 가 소유 프로젝트 id를 돌려준다. 자식이 없으면 null → $notFoundCode 404.
 *   자식이 있어도 프로젝트를 볼 수 없으면 같은 $notFoundCode 404(자식의 존재도 숨김).
 * - 통과하면 요청 속성 project_id에 프로젝트 id를 넣는다.
 */
final class RequireProjectAccess implements Middleware
{
    /**
     * @param \Closure(): ProjectAccess $access 지연 생성(라우트 등록 때 DB를 열지 않음)
     * @param (\Closure(string): ?int)|null $resolver 자식 id(경로 문자열) → 소유 프로젝트 id. 형식 검사도 여기서
     */
    public function __construct(
        private readonly \Closure $access,
        public readonly string $param = 'id',
        private readonly ?\Closure $resolver = null,
        public readonly string $notFoundCode = 'project_not_found',
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $raw = $request->param($this->param);
        if ($raw === null) {
            throw new ApiException(404, $this->notFoundCode);
        }
        if ($this->resolver === null) {
            if (preg_match('/^[1-9][0-9]{0,17}$/', $raw) !== 1) {
                throw new ApiException(404, $this->notFoundCode);
            }
            $projectId = (int) $raw;
        } else {
            // 자식 id 형식 검사는 resolver가 한다(정수 id 또는 upload_id 같은 문자열 id)
            $projectId = ($this->resolver)($raw);
        }
        if ($projectId === null) {
            throw new ApiException(404, $this->notFoundCode);
        }
        /** @var ProjectAccess $access */
        $access = ($this->access)();
        if (!$access->canView($request->attribute('user'), $projectId)) {
            // 프로젝트 경로는 ProjectAccess::requireView와 같은 응답(project_not_found), 자식 경로는 자식의 not_found
            if ($this->notFoundCode === 'project_not_found') {
                $access->requireView($request->attribute('user'), $projectId);
            }
            throw new ApiException(404, $this->notFoundCode);
        }
        return $next($request->withAttribute('project_id', $projectId));
    }
}
