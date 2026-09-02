#!/usr/bin/env python3
import sys

# Bu demo betiği, gerçek bir Türkçe LLM yerine basit bir normalizasyon sunar.
# İlerleyen adımlarda bu betiği gerçek bir LLM çağrısı ile değiştirebiliriz.

COMMON_FIXES = {
    'degil': 'değil',
    'saglik': 'sağlık',
    'guzel': 'güzel',
    'cok': 'çok',
    'ogren': 'öğren',
    'birsey': 'bir şey',
    'suan': 'şu an',
    'malesef': 'maalesef',
    'eminim': 'eminim',
}


def normalize(text: str) -> str:
    text = text.strip()
    for wrong, right in COMMON_FIXES.items():
        text = text.replace(wrong, right)
        text = text.replace(wrong.capitalize(), right.capitalize())

    if text and text[0].islower():
        text = text[0].upper() + text[1:]

    if not text.endswith('.') and not text.endswith('?') and not text.endswith('!'):
        text += '.'

    text = ' '.join(text.split())
    return text


def main():
    if len(sys.argv) > 1:
        source = ' '.join(sys.argv[1:])
    else:
        source = sys.stdin.read()

    if not source.strip():
        print('')
        return

    corrected = normalize(source)
    print(corrected)


if __name__ == '__main__':
    main()
