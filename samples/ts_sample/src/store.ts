// 상태 저장
type State = { items: string[] };

export function add(state: State, item: string, debug: boolean): State {
  return { items: [...state.items, item] };
}
