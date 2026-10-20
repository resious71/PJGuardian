<?php
/**
 * 설정 관리 "저장소 연결" 탭 안의 "허용 호스트" 구역(X12 — 예전 P25 별도 화면 /admin/repo-hosts를 옮김, admin 전용, 명세 18.4 SSRF 방지).
 * 목록에 없는 호스트에는 접속하지 않는다(localhost·사설망 포함). HTTP(평문)는 "HTTP 허용"으로 등록한 호스트만,
 * "GitHub 호환"이면 분석 이력에 커밋 링크(/{owner}/{repo}/commit/{hash})를 만든다(github.com은 자동).
 * 값은 DB 표(repo_allowed_hosts) — 설정 관리와 저장소가 달라 이 구역의 단추가 바로 API(/api/v1/admin/repo-hosts)로 쓴다(pages/admin_repo_hosts.js, textContent).
 * 설정 관리 화면이 이 구역을 "저장소 연결" 탭 패널 맨 위로 옮긴다.
 *
 * @var bool $allowLocalDev
 * @var int $hostTestMax P26B: 전체 테스트 한 번에 최대 호스트 수
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 */
?>
<section class="config-merge repo-hosts" data-repo-hosts data-merge-into="repository" data-merge-pos="start" aria-labelledby="rh-title">
  <h3 class="config-merge__title" id="rh-title"><?= $e($t('repo_hosts.title')) ?><span class="config-merge__dirty" data-merge-dirty hidden></span></h3>
  <?php if ($allowLocalDev): ?>
  <p class="notice notice--warn"><?= $icons->icon('system.warn', 16) ?> <?= $e($t('repo_hosts.local_dev_on')) ?></p>
  <?php endif; ?>
  <ul class="plain-list repo-hosts__rules">
    <li><?= $e($t('repo_hosts.rule_list')) ?></li>
    <li><?= $e($t('repo_hosts.rule_http')) ?></li>
    <li><?= $e($t('repo_hosts.rule_github')) ?></li>
  </ul>
  <form class="repo-hosts__form" data-host-form novalidate>
    <div class="field">
      <label class="field__label" for="rh-host"><?= $e($t('repo_hosts.field.host')) ?></label>
      <input class="field__input" id="rh-host" name="host" autocomplete="off" spellcheck="false" maxlength="253" required placeholder="github.example.com">
    </div>
    <div class="field">
      <label class="field__label" for="rh-port"><?= $e($t('repo_hosts.field.port')) ?></label>
      <div class="repo-hosts__port-row">
        <input class="field__input repo-hosts__port" id="rh-port" name="port" type="number" min="0" max="65535" step="1" inputmode="numeric" placeholder="0" aria-describedby="rh-port-help">
        <span class="muted repo-hosts__port-help" id="rh-port-help"><?= $e($t('repo_hosts.help.port')) ?></span>
      </div>
    </div>
    <label class="checkbox"><input type="checkbox" name="allow_http" value="1"> <?= $e($t('repo_hosts.field.allow_http')) ?></label>
    <label class="checkbox"><input type="checkbox" name="github_links" value="1"> <?= $e($t('repo_hosts.field.github_links')) ?></label>
    <div class="field">
      <label class="field__label" for="rh-note"><?= $e($t('repo_hosts.field.note')) ?></label>
      <input class="field__input" id="rh-note" name="note" maxlength="200" autocomplete="off">
    </div>
    <button type="submit" class="btn btn--primary" data-host-add><?= $e($t('repo_hosts.add')) ?></button>
  </form>
  <p class="notice notice--error" data-error role="alert" hidden></p>
  <p class="muted" data-status role="status" aria-live="polite"></p>
  <div class="repo-hosts__testbar">
    <button type="button" class="btn" data-test-all><?= $icons->withLabel('action.fetch_now', 16, $t('host_test.test_all')) ?></button>
    <span class="muted"><?= $e($t('host_test.test_all_note', ['max' => $hostTestMax])) ?></span>
  </div>
  <section class="repo-hosts__result" data-host-result aria-live="polite" aria-labelledby="rh-result-title" hidden>
    <h4 id="rh-result-title" data-result-title></h4>
    <div data-result-body></div>
  </section>
  <div class="table-scroll">
    <table class="table repo-hosts__table" data-host-table>
      <caption class="sr-only"><?= $e($t('repo_hosts.caption')) ?></caption>
      <thead><tr>
        <th scope="col"><?= $e($t('repo_hosts.col.host')) ?></th>
        <th scope="col"><?= $e($t('repo_hosts.col.port')) ?></th>
        <th scope="col"><?= $e($t('repo_hosts.col.http')) ?></th>
        <th scope="col"><?= $e($t('repo_hosts.col.github')) ?></th>
        <th scope="col"><?= $e($t('repo_hosts.col.note')) ?></th>
        <th scope="col"><?= $e($t('host_test.col.last')) ?></th>
        <th scope="col"><?= $e($t('host_test.col.cert')) ?></th>
        <th scope="col"><?= $e($t('repo_hosts.col.actions')) ?></th>
      </tr></thead>
      <tbody data-host-rows></tbody>
    </table>
  </div>
  <p class="muted" data-empty hidden><?= $e($t('repo_hosts.empty')) ?></p>
</section>
