// 결함이 없는 정상 파일
export function sum(values: number[]): number {
  return values.reduce((acc, v) => acc + v, 0);
}
