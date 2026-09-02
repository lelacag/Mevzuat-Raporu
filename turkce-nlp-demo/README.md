# Türkçe NLP Demo

`mevzuatraporu.com` altında çalışan Zemberek tabanlı yazım / biçim önerisi demosu.

## Bileşenler

- `index.php` — form UI (Orijinal / Önerilen; sarı highlight yalnızca öneride)
- `grammar_helper.php` — UTF-8 ile `ZemberekSuggest` Java sürecini çalıştırır
- `ZemberekSuggest.java` — düzeltme boru hattı
- `zemberek-full.jar` — Zemberek
- `zemberek-data/` — isteğe bağlı sentence normalizer + LM (bkz. alt README)

## Düzeltme boru hattı

1. Token düzeltme (leet decode, apostrof gürültüsü, deasciify, çoklu clitic peel, proper, spell)
2. İsteğe bağlı `TurkishSentenceNormalizer` (veri varsa)
3. Kalıntı peel + soru eki ünlü uyumu (`günde musunuz` → `günde misiniz`)
4. Cümle cilası (`?`, büyük/küçük harf)

Leet örnek: `3vl3r3 ş3nl1k b1r Gun'du musunuz?` → `Evlere şenlik bir günde misiniz?`

## Derleme

```bash
cd public_html/turkce-nlp-demo
javac -encoding UTF-8 -cp zemberek-full.jar ZemberekSuggest.java
```

CLI deneme:

```bash
printf '%s' "3vl3r3 ş3nl1k b1r Gun'du musunuz?" | java -Dfile.encoding=UTF-8 -cp "zemberek-full.jar:." ZemberekSuggest
```
