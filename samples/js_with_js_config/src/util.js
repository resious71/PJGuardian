// 미사용 변수와 미정의 이름
export function helper(input) {
  const unusedLocal = input * 2;
  return globalHelper(input);
}
