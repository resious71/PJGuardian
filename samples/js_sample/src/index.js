// PJGuardian 샘플: 진입점
import { formatName } from './lib/format.js';

const unusedVersion = '1.0.0';

export function start(user) {
  console.log(formatName(user));
  return undefinedRuntime.start();
}
