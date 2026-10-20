// TypeScript 샘플: 진입점
import { User, describeUser } from './user';

const unusedCount: number = 0;

export function main(user: User): string {
  return describeUser(user);
}
