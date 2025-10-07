<?php
/**
 * Plugin Name:       Ninja Forms to Zapier Connector
 * Description:       Automatycznie przekazuje dane z formularzy Ninja Forms do Zapiera z inteligentnym, wielojęzycznym mapowaniem pól.
 * Version:           3.0.0 (Robust Textarea Fallback Logic)
 * Author:            MGlowacki
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       nf-zapier-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Konfiguracja
define('AG_PAYLOAD_PREFIX', '');
define('AG_NF_ALWAYS_EXCLUDED_FORM_IDS', []);
define('AG_ZAPIER_HOST', 'hooks.zapier.com');

// Hooki
add_action('ninja_forms_submit_data', 'ag_nf_handle_form_submission', 10, 1);
add_filter('ag_nf_ruleset', 'ag_nf_default_ruleset');
add_filter('ag_nf_manual_map', fn() => []);

// Admin notice gdy brak webhooka
function ag_nf_check_webhook_defined() {
    if ( ! defined( 'AG_ZAPIER_WEBHOOK' ) || empty( AG_ZAPIER_WEBHOOK ) ) {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            __( "<b>Wtyczka 'Ninja Forms to Zapier Connector' jest aktywna, ale nie może działać.</b> Proszę, dodaj swój URL webhooka do pliku <code>wp-config.php</code>, np: <code>define( 'AG_ZAPIER_WEBHOOK', 'https://hooks.zapier.com/...' );</code>", 'nf-zapier-connector' )
        );
    }
}
add_action( 'admin_notices', 'ag_nf_check_webhook_defined' );

// ===== Pomocnicze: rozpoznawanie typu i jakości pola wiadomości =====

/**
 * Czy typ pola jest tekstowy i nadaje się na wiadomość od użytkownika?
 */
function ag_nf_is_textual_message_field_type(string $type): bool {
    $type = strtolower($type);
    // Tekst użytkownika – szeroki, ale bez typów technicznych:
    $allowed = [
        'textarea', 'textbox', 'text', 'paragraph', 'richtext', 'wysiwyg', 'textarea_rte'
    ];
    return in_array($type, $allowed, true);
}

/**
 * Czy pole jest wyraźnie meta/analityczne (nie treść użytkownika)?
 * Sprawdza etykietę/klucz/typ pod kątem UTM, analytics, referer, itp.
 */
function ag_nf_is_meta_or_tracking_field(string $label_s, string $key_s, string $type): bool {
    $type = strtolower($type);

    // Wyklucz typy oczywistych meta
    $type_meta_prefixes = ['user-analytics', 'hidden', 'submit', 'recaptcha', 'spam', 'hr', 'html', 'info', 'divider'];
    foreach ($type_meta_prefixes as $prefix) {
        if (str_starts_with($type, $prefix)) {
            return true;
        }
    }

    // Wzorce meta/analytics w label/key
    $meta_needles = [
        'utm','user analytics','analytics','pys','traffic','landing page','landing-page',
        'referer','referrer','gclid','fbclid','campaign','medium','source','term','cookie'
    ];
    foreach ($meta_needles as $needle) {
        if (str_contains($label_s, $needle) || str_contains($key_s, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Czy to pole jest dobrym kandydatem na "Wiadomosc"?
 */
function ag_nf_should_map_to_message(string $type, string $label_s, string $key_s, $value): bool {
    if (!ag_nf_is_textual_message_field_type($type)) {
        return false;
    }
    if (ag_nf_is_meta_or_tracking_field($label_s, $key_s, $type)) {
        return false;
    }
    // Wartość musi być niepusta po przycięciu
    $val = is_scalar($value) ? trim((string)$value) : trim(wp_json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR));
    return $val !== '';
}

/**
 * Czy wartość jest "słaba" (np. n/a, null, krótka)?
 */
function ag_nf_is_weak_text($text): bool {
    if ($text === null) return true;
    $t = is_scalar($text) ? mb_strtolower(trim((string)$text)) : '';
    if ($t === '') return true;
    $weak = ['n/a','na','null','none','-','brak','n\\a'];
    return in_array($t, $weak, true) || mb_strlen($t) < 2;
}

/**
 * Zwraca ocenę (score) przydatności pola jako "Wiadomosc".
 * Preferuje textarea i dłuższą treść.
 */
function ag_nf_message_candidate_score(string $type, $value): int {
    $val = is_scalar($value) ? trim((string)$value) : trim(wp_json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR));
    if ($val === '') return -1;
    $len = mb_strlen($val);
    $bonus = (strtolower($type) === 'textarea') ? 100000 : 10000; // silna preferencja textarea
    return $bonus + min($len, 1000000);
}

// ===== Główny przepływ =====

function ag_nf_handle_form_submission(array $form_data): array
{
    $form_id = ag_nf_get_form_id_from_data($form_data);
    $additional_excluded_ids = apply_filters('ag_nf_excluded_form_ids', []);
    $final_excluded_ids = array_unique(array_merge(AG_NF_ALWAYS_EXCLUDED_FORM_IDS, $additional_excluded_ids));
    if (in_array($form_id, $final_excluded_ids, true)) {
        return $form_data;
    }

    $fields_raw = $form_data['fields'] ?? [];
    $form_title = $form_data['settings']['title'] ?? 'Brak tytułu';
    $ruleset    = apply_filters('ag_nf_ruleset', []);
    $manual_map = apply_filters('ag_nf_manual_map', []);

    $mapped = ag_nf_map_fields($fields_raw, $ruleset, $manual_map[$form_id] ?? []);

    $payload = array_merge(['Form Title' => $form_title], ag_nf_get_tracking_data(), $mapped);
    ag_nf_send_to_zapier($payload);

    return $form_data;
}

function ag_nf_default_ruleset(): array
{
    $dictionaries = ag_nf_get_field_dictionaries();
    return [
        100 => [
            build_complex_regex($dictionaries['consent'], $dictionaries['email']) => 'Zgoda mail',
            build_complex_regex($dictionaries['consent'], $dictionaries['sms'])   => 'Zgoda sms',
        ],
        90 => [
            build_regex($dictionaries['name'])    => 'Imię i nazwisko',
            build_regex($dictionaries['email'])   => 'Adres Email',
            build_regex($dictionaries['phone'])   => 'Telefon',
            build_regex($dictionaries['message']) => 'Wiadomosc',
        ],
        20  => [
            build_regex($dictionaries['postal_code'])  => 'Kod Pocztowy Powiat',
            build_regex($dictionaries['machinery'])    => 'Maszyna',
        ],
    ];
}

function ag_nf_get_field_dictionaries(): array
{
    return [
        'name'      => ['imie', 'imię', 'nazwisko', 'name', 'firstname', 'lastname', 'vorname', 'nachname', 'nom', 'prenom', 'apellido', 'nombre'],
        'email'     => ['email', 'e-mail', 'mail', 'emailadresse', 'correo', 'courriel', 'posta elektroniczna', 'adres email', 'adres e-mail'],
        'phone'     => ['telefon', 'phone', 'tel', 'telefonnummer', 'telefono', 'telephone', 'komorkowy', 'mobile', 'numer', 'kontaktowy'],
        // 'content' zostaje dla wielojęzyczności, ale "Wiadomosc" i tak filtrujemy typem/anty-meta
        'message'   => ['wiadomosc', 'wiadomość', 'nachricht', 'message', 'mensaje', 'texte', 'treść', 'tresc', 'content', 'pytanie'],
        'consent'   => ['zgod', 'zgoda', 'handlow', 'marketingow', 'einverstanden', 'zustimmung', 'consent', 'agreement', 'autorisation', 'otrzymywanie'],
        'sms'       => ['sms', 'tekstow', 'text message'],
        'postal_code' => ['kod pocztowy', 'pocztowy', 'postleitzahl', 'zip', 'postal', 'code postal', 'codigo postal'],
        'machinery' => ['ciągnik', 'ciagnik', 'maszyny', 'maszyna', 'dienstleistung', 'art der dienstleistung', 'equipment', 'machinery', 'service', 'wybierz ciagnik', 'wybierz rodzaj uslugi'],
    ];
}

function build_regex(array $keywords): string
{
    $escaped = array_map(fn($kw) => preg_quote($kw, '/'), $keywords);
    usort($escaped, fn($a, $b) => strlen($b) <=> strlen($a));
    return '/\b(' . implode('|', $escaped) . ')\b/i';
}

function build_complex_regex(array $first_group, array $second_group): string
{
    $first_escaped  = array_map(fn($kw) => preg_quote($kw, '/'), $first_group);
    $second_escaped = array_map(fn($kw) => preg_quote($kw, '/'), $second_group);
    usort($first_escaped, fn($a, $b) => strlen($b) <=> strlen($a));
    usort($second_escaped, fn($a, $b) => strlen($b) <=> strlen($a));
    $first_pattern  = '(' . implode('|', $first_escaped) . ')';
    $second_pattern = '(' . implode('|', $second_escaped) . ')';
    return '/' . $first_pattern . '.*' . $second_pattern . '/i';
}

function ag_nf_map_fields(array $fields, array $ruleset, array $manual): array
{
    $payload = [];
    $list_cache = [];

    // Kandydat "best effort" dla Wiadomości
    $best_message_candidate = null;
    $best_message_score = -1;

    foreach ($fields as $field) {
        $key   = $field['key'] ?? '';
        $type  = $field['settings']['type'] ?? ($field['type'] ?? 'unknown');
        $value = $field['value'] ?? null;

        // Pomiń ewidentnie nietreściowe/techniczne lub puste
        if (in_array($type, ['submit', 'html', 'hr', 'recaptcha', 'spam', 'info', 'divider'], true) || is_null($value) || '' === $value) {
            continue;
        }

        $raw_label = $field['settings']['label'] ?? ($field['label'] ?? '');
        $label_s = ag_nf_prepare_search($raw_label);
        $key_s   = ag_nf_prepare_search($key);

        // Manual map (jeśli ktoś użyje filtra)
        if (isset($manual[$key])) {
            $payload[ag_nf_prefix($manual[$key])] = ag_nf_normalize($type, $value, $field, $list_cache, $manual[$key]);
            // Nie przerywamy "best candidate" – też ocenimy to pole poniżej
        } else {
            // Automatyczne reguły
            $mapped = false;

            foreach ($ruleset as $rules) {
                foreach ($rules as $pattern => $target) {
                    if (preg_match($pattern, $label_s) || preg_match($pattern, $key_s)) {

                        // Ograniczenie dla nadmiernie ogólnych etykiet imię/mail/telefon
                        if (in_array($target, ['Imię i nazwisko', 'Adres Email', 'Telefon']) && str_word_count($label_s) > 4) {
                            continue;
                        }

                        // Krytyczne: Jeśli target to "Wiadomosc", najpierw zweryfikuj typ/charakter pola
                        if ('Wiadomosc' === $target) {
                            if (! ag_nf_should_map_to_message($type, $label_s, $key_s, $value)) {
                                // To dopasowanie nie nadaje się jako wiadomość – spróbuj kolejnego wzorca
                                continue;
                            }
                        }

                        $payload[ag_nf_prefix($target)] = ag_nf_normalize($type, $value, $field, $list_cache, $target);
                        $mapped = true;
                        break 2;
                    }
                }
            }

            // Jeśli nie zmapowano — nadaj nazwę generyczną
            if (! $mapped) {
                $slug = ag_nf_slugify($label_s ?: $key_s);
                $payload[ag_nf_prefix($slug)] = ag_nf_normalize($type, $value, $field, $list_cache, $slug);
            }
        }

        // Równolegle: oceń to pole jako potencjalną "Wiadomosc"
        if (ag_nf_should_map_to_message($type, $label_s, $key_s, $value)) {
            $score = ag_nf_message_candidate_score($type, $value);
            if ($score > $best_message_score) {
                $best_message_score = $score;
                $best_message_candidate = $field;
            }
        }
    }

    // Finalizacja klucza "Wiadomosc" – ZAWSZE standaryzujemy klucz:
    $message_key = ag_nf_prefix('Wiadomosc');
    $has_message = array_key_exists($message_key, $payload);
    $needs_override = !$has_message || ag_nf_is_weak_text($payload[$message_key] ?? null);

    if ($needs_override && $best_message_candidate) {
        $bm       = $best_message_candidate;
        $bm_type  = $bm['settings']['type'] ?? ($bm['type'] ?? 'unknown');
        $bm_value = $bm['value'] ?? '';
        $normalized = ag_nf_normalize($bm_type, $bm_value, $bm, $list_cache, 'Wiadomosc');

        // 1) ZAWSZE ustaw stały klucz:
        $payload[$message_key] = $normalized;

        // 2) (Opcjonalnie: okres przejściowy) można dodać alias-slug z etykiety:
        // $raw_label = $bm['settings']['label'] ?? ($bm['label'] ?? '');
        // $alias_slug = ag_nf_slugify( ag_nf_prepare_search($raw_label ?: ($bm['key'] ?? '')) );
        // if ($alias_slug && !isset($payload[$alias_slug])) {
        //     $payload[$alias_slug] = $normalized; // usuń po przełączeniu Zapa
        // }
    }

    return $payload;
}

/**
 * Normalizuje wartość pola w zależności od jego typu LUB przeznaczenia.
 * @param string $target Klucz docelowy (np. 'Zgoda mail').
 */
function ag_nf_normalize(string $type, $value, array $field, array &$cache, string $target = '')
{
    $consent_keys = ['Zgoda mail', 'Zgoda sms'];
    if (in_array($target, $consent_keys) || in_array($type, ['checkbox', 'toggle'], true)) {
        return ('1' === strval($value) || 'Zaznaczone' === $value || true === $value) ? 'tak' : 'nie';
    }

    if ('listselect' === $type) {
        if (! isset($cache[$field['key']])) {
            $map = [];
            foreach (($field['settings']['options'] ?? []) as $opt) {
                if (isset($opt['value'], $opt['label'])) {
                    $map[strval($opt['value'])] = $opt['label'];
                }
            }
            $cache[$field['key']] = $map;
        }
        return $cache[$field['key']][strval($value)] ?? $value;
    }

    // Dla pól tekstowych – przekaż czysty tekst
    if (ag_nf_is_textual_message_field_type($type)) {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        return wp_json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    return is_scalar($value) ? strval($value) : wp_json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function ag_nf_prepare_search(string $str): string
{
    $str = strip_tags($str);
    $str = remove_accents($str);
    $str = preg_replace('/_[0-9]+$/', '', $str);
    $str = str_replace(['_', '-'], ' ', $str);
    return mb_strtolower(trim($str));
}

function ag_nf_slugify(string $label): string
{
    $label = remove_accents($label);
    return str_replace('-', '_', sanitize_title($label));
}

function ag_nf_prefix(string $key): string
{
    return AG_PAYLOAD_PREFIX ? AG_PAYLOAD_PREFIX . $key : $key;
}

function ag_nf_get_tracking_data(): array
{
    $trk = [
        'pys_traffic_source' => isset($_COOKIE['pysTrafficSource']) ? sanitize_key($_COOKIE['pysTrafficSource']) : null,
        'pys_utm_medium'     => isset($_COOKIE['pys_utm_medium']) ? sanitize_key($_COOKIE['pys_utm_medium']) : null,
        'pys_utm_source'     => isset($_COOKIE['pys_utm_source']) ? sanitize_key($_COOKIE['pys_utm_source']) : null,
        'pys_landing_page'   => isset($_COOKIE['pys_landing_page']) ? esc_url_raw($_COOKIE['pys_landing_page']) : null,
        'submission_url'     => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : 'not_set',
    ];
    $trk['submission_datetime'] = date_i18n('Y-m-d H:i:s', current_time('timestamp'));
    $trk['calculated_source']   = ag_nf_calculate_source($trk);
    return [
        'Calculated Source' => $trk['calculated_source'],
        'Pys Traffic Source' => $trk['pys_traffic_source'],
        'Pys Utm Medium'   => $trk['pys_utm_medium'],
        'Pys Utm Source'   => $trk['pys_utm_source'],
        'Pys Landing Page' => $trk['pys_landing_page'],
        'Submission Url'   => $trk['submission_url'],
        'Submission Datetime' => $trk['submission_datetime'],
    ];
}

function ag_nf_calculate_source(array $d): string
{
    $utmMedium = $d['pys_utm_medium'] ?? null;
    $utmSource = $d['pys_utm_source'] ?? null;
    $trafficSource = $d['pys_traffic_source'] ?? null;

    if ('cpc' === $utmMedium) {
        return match ($utmSource) {
            'google'   => 'google cpc',
            'facebook' => 'facebook cpc',
            default    => 'inne cpc',
        };
    }

    if ('newsletter' === $utmSource) {
        return match ($utmMedium) {
            'email' => 'newsletter',
            'sms'   => 'Kampania SMS',
            default => 'Newsletter Inne',
        };
    }

    if (empty($utmMedium) || 'null' === $utmMedium) {
        if ('direct' === $trafficSource) {
            return 'direct';
        }

        if (null !== $trafficSource && str_contains($trafficSource, 'instagram')) {
            return 'instagram organic';
        }

        if (
            null !== $trafficSource &&
            (str_contains($trafficSource, 'facebook') ||
             str_contains($trafficSource, 'linkedin') ||
             str_contains($trafficSource, 'messenger'))
        ) {
            return 'facebook organic';
        }

        if (
            null !== $trafficSource &&
            (str_contains($trafficSource, 'google') ||
             str_contains($trafficSource, 'bing') ||
             str_contains($trafficSource, 'chat') ||
             str_contains($trafficSource, 'yahoo') ||
             str_contains($trafficSource, 'perplexity'))
        ) {
            return 'organic';
        }

        return 'referral';
    }

    return 'inne';
}

function ag_nf_send_to_zapier(array $payload): void
{
    if (! defined('AG_ZAPIER_WEBHOOK') || empty(AG_ZAPIER_WEBHOOK)) {
        error_log('Zapier Error: AG_ZAPIER_WEBHOOK is not defined in wp-config.php.');
        return;
    }
    $url = AG_ZAPIER_WEBHOOK;
    if (wp_parse_url($url, PHP_URL_HOST) !== AG_ZAPIER_HOST) {
        error_log('Zapier Error: Webhook host does not match the allowed host: ' . $url);
        return;
    }
    $resp = wp_remote_post($url, [
        'body'        => wp_json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR),
        'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
        'timeout'     => 5,
        'redirection' => 1,
        'blocking'    => false,
    ]);
    if (is_wp_error($resp)) {
        error_log('Zapier Error: ' . $resp->get_error_message());
    }
}

function ag_nf_get_form_id_from_data(array $form_data): int
{
    $possible_keys = ['form_id', 'id'];
    foreach ($possible_keys as $key) {
        if (isset($form_data[$key]) && is_numeric($form_data[$key])) {
            return (int) $form_data[$key];
        }
    }
    return 0;
}
