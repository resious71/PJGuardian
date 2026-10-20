// 사용자 타입
export interface User {
  id: number;
  name: string;
  extra: any;
}

export function describeUser(user: User): string {
  return `${user.id}:${user.name}`;
}
