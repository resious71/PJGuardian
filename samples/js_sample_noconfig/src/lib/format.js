// 이름 형식화
export function formatName(user) {
  if (user == null) {
    return '';
  }
  return user.first + ' ' + user.last;
}
