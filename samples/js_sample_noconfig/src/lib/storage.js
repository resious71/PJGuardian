// 저장소 도우미
export function save(key, value) {
  const unusedPrefix = 'pjg:';
  localStorage.setItem(key, JSON.stringify(value));
}

export function load(key) {
  return JSON.parse(localStorage.getItem(key)) || missingDefault;
}
