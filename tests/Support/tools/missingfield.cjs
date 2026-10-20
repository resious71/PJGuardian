'use strict';
// 시험용 가짜 ESLint: 필수 필드(line)가 없는 메시지
process.stdout.write(JSON.stringify({ filePath: 'a.js', ruleId: 'x', message: 'm', extra: 1 }) + '\n');
process.exit(1);
