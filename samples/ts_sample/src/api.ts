// API 응답 처리
export function parse(body: any): any {
  const unusedHeader = 'x-pjg';
  return JSON.parse(body);
}
