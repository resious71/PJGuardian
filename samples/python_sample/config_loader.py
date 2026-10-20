"""설정 로더 샘플."""


def load(settings):
    if settings == None:
        return {}
    if settings.get("debug") == True:
        print("debug mode")
    return settings
