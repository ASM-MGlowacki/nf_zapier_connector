# Ninja Forms to Zapier Connector

Automatycznie przekazuje dane z formularzy Ninja Forms do Zapiera z inteligentnym, wielojęzycznym mapowaniem pól i stabilnym wyznaczaniem pola Wiadomosc (w tym preferencja dla pól typu textarea).

Wersja pluginu: 3.0.0 (Robust Textarea Fallback Logic)

## Wymagania

- WordPress 6.x+
- Ninja Forms
- Konto Zapier oraz URL webhooka (Zapier Catch Hook)

## Instalacja

1. Skopiuj plik `nf-zapier-connector.php` do katalogu wtyczki, np. `wp-content/plugins/nf-zapier-connector/`.
2. Aktywuj wtyczkę w `Kokpit -> Wtyczki`.

## Konfiguracja

Dodaj w `wp-config.php` stałą z adresem webhooka Zapier:

```php
define('AG_ZAPIER_WEBHOOK', 'https://hooks.zapier.com/hooks/catch/XXXXXXXXX/XXXXXXXXX');
```

Opcjonalnie możesz dostosować:

- `AG_PAYLOAD_PREFIX` – prefiks kluczy wysyłanych do Zapiera (domyślnie brak).
- `AG_NF_ALWAYS_EXCLUDED_FORM_IDS` – lista ID formularzy Ninja Forms, które mają być całkowicie pominięte.
- `AG_ZAPIER_HOST` – dozwolony host webhooka (domyślnie `hooks.zapier.com`).

## Jak to działa

Wtyczka słucha zdarzenia `ninja_forms_submit_data`, mapuje pola według inteligentnych reguł oraz ręcznych nadpisań (jeśli dodasz filtry) i wysyła JSON do Zapiera metodą `wp_remote_post`.

- Kluczowe mapowania automatyczne: `Imię i nazwisko`, `Adres Email`, `Telefon`, `Wiadomosc`, `Zgoda mail`, `Zgoda sms`, `Kod Pocztowy Powiat`, `Maszyna`.
- Pole `Wiadomosc` jest wyznaczane stabilnie: preferowane są pola tekstowe (`textarea`), odrzucane są pola meta/analityczne (UTM, referer, recaptcha itp.). Jeśli nie ma jawnego dopasowania, wybierany jest najlepszy kandydat na podstawie długości treści i typu pola.
- Do payloadu dołączane są też dane śledzące (m.in. `Calculated Source`, `Pys Traffic Source`, `Pys Utm Medium`, `Pys Utm Source`, `Pys Landing Page`, `Submission Url`, `Submission Datetime`).
- Wysyłka jest nieblokująca (`blocking => false`).
- Bezpieczeństwo: sprawdzany jest host webhooka (musi być zgodny z `AG_ZAPIER_HOST`).

## Wyznaczanie wartości „Calculated Source”

Wartość `Calculated Source` jest wyliczana na podstawie `pys_utm_medium`, `pys_utm_source` oraz `pys_traffic_source`:

- **Kampanie płatne (CPC)**: gdy `utm_medium = cpc`
  - `utm_source = google` → `google cpc`
  - `utm_source = facebook` → `facebook cpc`
  - inne źródła → `inne cpc`

- **Newsletter (rozgałęzienie po medium)**: gdy `utm_source = newsletter`
  - `utm_medium = email` → `newsletter`
  - `utm_medium = sms` → `Kampania SMS`
  - inne/ brak → `Newsletter Inne`

- **Ruch organiczny (bez jawnego medium)**: gdy `pys_utm_medium` jest puste lub `'null'`
  - `traffic_source = direct` → `direct`
  - `traffic_source` zawiera `instagram` → `instagram organic`
  - `traffic_source` zawiera `facebook`, `linkedin` lub `messenger` → `facebook organic`
  - `traffic_source` zawiera `google`, `bing`, `chat`, `yahoo`, `perplexity` → `organic`
  - w pozostałych przypadkach → `referral`

- **Fallback**: jeśli żaden z powyższych warunków nie pasuje → `inne`.

Uwaga: `pys_traffic_source` jest traktowane jako znormalizowane (małe litery), dzięki czemu dopasowania `str_contains` działają przewidywalnie.

## Zapier – szybki start

1. W Zapier utwórz Zapa: `Webhooks by Zapier -> Catch Hook`.
2. Skopiuj wygenerowany URL, wklej do `wp-config.php` jako `AG_ZAPIER_WEBHOOK`.
3. Wyślij testowy formularz w Ninja Forms – Zapier odbierze przykładowy payload do dalszej konfiguracji pól.

## API: filtry i rozszerzenia

- `ag_nf_ruleset` – pozwala dostarczyć/zmodyfikować zestaw reguł mapowania (regex -> nazwa pola w Zapier).
- `ag_nf_manual_map` – umożliwia ręczne mapowanie po kluczach pól z Ninja Forms per ID formularza.
- `ag_nf_excluded_form_ids` – lista dodatkowych ID formularzy do pominięcia.

### Przykład: ręczne mapowanie pola

```php
add_filter('ag_nf_manual_map', function(array $map) {
    // Mapowanie dla formularza o ID 12: klucz pola 'your_field_key' -> 'Nazwa Docelowa'
    $map[12] = [
        'your_field_key' => 'Nazwa Docelowa',
    ];
    return $map;
});
```

### Przykład: wykluczenie formularza z integracji

```php
add_filter('ag_nf_excluded_form_ids', function(array $ids) {
    $ids[] = 34; // pomiń formularz 34
    return $ids;
});
```

## Diagnostyka

- Gdy `AG_ZAPIER_WEBHOOK` nie jest ustawiony, wtyczka doda informację w kokpicie i zaloguje błąd w `error_log`.
- Przy nieprawidłowym hoście webhooka (innym niż `AG_ZAPIER_HOST`) wysyłka zostanie zablokowana i zalogowana.

## Licencja

GPL-2.0+. Szczegóły w nagłówku pliku `nf-zapier-connector.php`..

---

Repozytorium docelowe (puste w momencie przygotowania):

- GitHub: https://github.com/ASM-MGlowacki/nf_zapier_connector


