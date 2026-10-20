// 설정을 따르면 결함 0건, 서버 기본 설정이면 결함이 보고된다
const unusedFlag = true;

export function run() {
  return notDefinedAnywhere + 1;
}
