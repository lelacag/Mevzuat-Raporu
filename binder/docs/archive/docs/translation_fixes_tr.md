Translation fixes (Turkish) — audit and applied changes

Summary: I corrected several Turkish strings that were missing diacritics or used less natural phrasing. Below are the original strings found in the repository and the corrected forms I applied.

| Key | Original | Corrected |
|-----|----------|-----------|
| post_replies | Yanitlar | Yanıtlar |
| profile_edit_btn | Profili Duzenle | Profili Düzenle |
| profile_unfollow_btn | Takibi Birak | kuyrugu bırak |
| profile_joined | Katilim: %s | Katılım: %s |
| profile_posts_stat | Gonderi | Gönderi |
| profile_not_found_title | Kullanici Bulunamadi | Kullanıcı Bulunamadı |
| profile_not_found_text | Aradiginiz kullanici mevcut degil veya silinmis. | Aradığınız kullanıcı mevcut değil veya silinmiş. |
| empty_state_hint | Yeni gonderiler paylasildiginda veya takip edildiginde burada gorunecek. | Yeni gönderiler paylaşıldığında veya takip edildiğinde burada görünecek. |
| notification_like | Gonderini begendi | Gönderini beğendi |
| notification_reply | Gonderine yanit verdi | Gönderine yanıt verdi |

Notes & next steps:
- There are more strings in `lang/tr.php` and elsewhere that may benefit from a full native-speaker pass (e.g., tone, punctuation, capitalization). If you want, I can do a full sweep and propose all changes as one PR.
- If you'd like these corrections applied elsewhere (templates or inline phrases inside PHP files), tell me and I will expand the search.
