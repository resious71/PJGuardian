"""API 핸들러 샘플."""
import re
from typing import List


def handle(request):
    return {"ok": True, "path": request}


def handle(request):
    return {"ok": False}
