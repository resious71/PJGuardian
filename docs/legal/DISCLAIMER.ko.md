# 면책 고지

[English](DISCLAIMER.md)

PJGuardian은 [Apache License 2.0](../../LICENSE) 7·8조에 따라 **"있는 그대로(AS IS)" 제공되며 어떠한 보증도 하지 않습니다**(명시적이든 묵시적이든). 저작자와 기여자는 이 소프트웨어의 사용으로 생긴 어떤 청구·손해·책임에 대해서도 책임지지 않습니다.

## PJGuardian이 하는 일과 하지 않는 일

- PJGuardian은 서드파티 정적 분석 도구(Python은 ruff, JavaScript/TypeScript는 ESLint)의 결과를 모아 보여 주고 팀이 함께 추적하도록 돕습니다.
- 분석한 코드의 품질·정확성·보안을 **보증하지 않습니다**. 결함이 없다는 결과는 설정된 도구 규칙이 아무것도 보고하지 않았다는 뜻일 뿐입니다. 규칙은 문제를 놓치거나 잘못 보고할 수 있습니다.
- 규칙 설명과 "쉬운 설명"은 도움말입니다. 기준은 각 도구의 공식 문서입니다.

## 운영자의 책임

PJGuardian을 설치해 운영하는 사람(운영자)의 책임입니다.

- DB·설정·암호화 키·스냅샷 백업과 복원 시험(`docs/backup_restore.md`)
- 서버 보안: HTTPS, 설치 직후 초기 `admin` 비밀번호 변경, 파일 권한, 방화벽, PHP 등 구성요소 업데이트(`docs/hardening.md`)
- `bin/`에 두는 외부 도구(ruff, ESLint, Node.js, Git, 특히 이 프로젝트가 재배포하지 않는 독점 소프트웨어 Perforce `p4`)의 라이선스
- 서비스가 저장하는 데이터를 사용자에게 알리고([PRIVACY.md](PRIVACY.md)), 조직에 적용되는 법과 정책을 지키는 일

이 문서는 법률 자문이 아닙니다.
