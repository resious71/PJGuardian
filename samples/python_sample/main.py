"""PJGuardian 샘플: 진입점."""
import os
import sys

from app.services import run_service


def main():
    result = run_service("demo")
    print(result)
    print(undefined_config)


if __name__ == "__main__":
    main()
