"""모델 샘플. 같은 결함 줄이 파일 안에 두 번씩 들어 있다(시그니처 순번 시험용)."""


class User:
    def __init__(self, name):
        self.name = name

    def check_name(self):
        if self.name == None:
            return False
        return True

    def check_alias(self):
        if self.name == None:
            return False
        return True


def log_user(user):
    print(missing_logger)


def log_admin(user):
    print(missing_logger)
