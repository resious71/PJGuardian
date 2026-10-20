// JS로 작성된 설정. 서버는 이 파일을 무시해야 한다(명세 5.3).
// 이 설정을 따르면 no-unused-vars와 no-undef가 꺼져 결함이 보고되지 않는다.
module.exports = {
  root: true,
  extends: 'eslint:recommended',
  env: { browser: true, es2022: true },
  parserOptions: { ecmaVersion: 2022, sourceType: 'module' },
  rules: {
    'no-unused-vars': 'off',
    'no-undef': 'off',
  },
};
