<?php
/**
 * 아이콘 범례(명세 16.1, 20.3). 스타일 가이드와 사용자·관리자 가이드(P28)가 함께 쓰는 재사용 컴포넌트.
 * icons.json의 그룹 순서대로, 의미 이름별 아이콘·라벨·접근성 라벨을 표로 보여 준다.
 *
 * @var list<string>|null $groups 보여 줄 그룹(없으면 전부). 사용자 가이드는 'admin', 'ui'를 뺄 수 있다
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var array<string, string> $iconGroups 그룹 이름 → 라벨 키(icons.json groups)
 * @var bool|null $guide X12: 가이드 범례 — 아이콘은 큰 것(24px) 하나만, 표마다 카테고리 이름 caption, 카테고리 제목은 가이드 소제목 모양(md-heading),
 *                       표는 영역 안에서만 가로 스크롤(.icon-legend__scroll). 스타일 가이드(기본)는 지금처럼 세 크기
 */
$selected = $groups ?? array_keys($iconGroups);
$guide = !empty($guide);
$sizes = $guide ? [24] : [16, 20, 24];
?>
<div class="icon-legend<?= $guide ? ' icon-legend--guide' : '' ?>">
<?php foreach ($selected as $group): ?>
  <section class="icon-legend__group" data-legend-group="<?= $e($group) ?>">
    <h3<?= $guide ? ' class="md-heading" data-toc="skip"' : '' ?>><?= $e($t($iconGroups[$group])) ?></h3>
    <?php if ($guide): ?><div class="icon-legend__scroll" tabindex="0" role="region" aria-label="<?= $e($t($iconGroups[$group])) ?>"><?php endif; ?>
    <table class="table table--compact">
      <?php if ($guide): ?><caption class="sr-only"><?= $e($t($iconGroups[$group])) ?></caption><?php endif; ?>
      <thead><tr>
        <th scope="col"><?= $e($t('styleguide.legend_col_icon')) ?></th>
        <th scope="col"><?= $e($t('styleguide.legend_col_label')) ?></th>
        <th scope="col"><?= $e($t('styleguide.legend_col_aria')) ?></th>
        <th scope="col"><?= $e($t('styleguide.legend_col_name')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($icons->names($group) as $name): ?>
        <tr data-legend="<?= $e($name) ?>">
          <td class="icon-legend__icons"><?php foreach ($sizes as $size): ?><?= $icons->icon($name, $size) ?><?php endforeach; ?></td>
          <td><?= $e($icons->label($name)) ?></td>
          <td><?= $e($icons->aria($name)) ?></td>
          <td><code><?= $e($name) ?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($guide): ?></div><?php endif; ?>
  </section>
<?php endforeach; ?>
</div>
