"""서비스 샘플."""
import json


def run_service(name):
    unused_total = 0
    try:
        return "service:" + name
    except:
        return None
