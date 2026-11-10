<?php
// Calend.ru daily holidays proxy. Compatible with PHP 7.1.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond($ok, $holidays, $error) {
    echo json_encode(array(
        'ok' => $ok,
        'holidays' => $holidays,
        'error' => $error
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    respond(false, array(), 'Некорректная дата');
}
$dt = DateTime::createFromFormat('!Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date) {
    respond(false, array(), 'Некорректная дата');
}

$url = 'https://www.calend.ru/day/' . $date . '/';
$html = false;
$httpCode = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AlkashnyaHolidayBot/1.0)',
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ));
    $html = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($html === false) {
        respond(false, array(), 'Ошибка запроса Calend.ru: ' . $curlError);
    }
} else {
    $context = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "User-Agent: Mozilla/5.0 (compatible; AlkashnyaHolidayBot/1.0)\r\n"
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true)
    ));
    $html = @file_get_contents($url, false, $context);
    if ($html === false) {
        respond(false, array(), 'Хостинг не смог получить страницу Calend.ru');
    }
}

if ($httpCode && ($httpCode < 200 || $httpCode >= 300)) {
    respond(false, array(), 'Calend.ru вернул HTTP ' . $httpCode);
}
if (!is_string($html) || strlen($html) < 500) {
    respond(false, array(), 'Calend.ru вернул пустой или слишком короткий ответ');
}

$months = array(
    1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
    5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
    9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'
);
$heading = (int)$dt->format('j') . ' ' . $months[(int)$dt->format('n')] . ' ' . $dt->format('Y') . ' года';

$holidays = array();
if (class_exists('DOMDocument')) {
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($loaded) {
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//text()[normalize-space()]');
        $texts = array();
        foreach ($nodes as $node) {
            $value = html_entity_decode($node->nodeValue, ENT_QUOTES, 'UTF-8');
            $value = preg_replace('/\s+/u', ' ', trim($value));
            if ($value !== '') $texts[] = $value;
        }

        $datePositions = array();
        foreach ($texts as $i => $value) {
            $plainHeading = preg_replace('/\s+/u', ' ', $heading);
            if (preg_match('/^' . preg_quote($plainHeading, '/') . '(?:,|$)/u', $value)) {
                $datePositions[] = $i;
            }
        }

        // The detailed holiday list follows the second occurrence of the date.
        $start = count($datePositions) > 1 ? $datePositions[1] : (count($datePositions) ? $datePositions[0] : -1);
        if ($start >= 0) {
            for ($i = $start + 1; $i < count($texts) && count($holidays) < 8; $i++) {
                $name = trim($texts[$i]);
                if (stripos($name, 'Все праздники в этот день') !== false) break;
                if (preg_match('/^(Праздники|Завтра|Послезавтра|Сегодня|В народном календаре|Именины|Хроника|Персоны дня|\.\.\.)/ui', $name)) continue;
                if (preg_match('/^\d{1,2}\s+[а-яё]+\s+\d{4}/ui', $name)) break;
                if (preg_match('/^(Image|Праздники России|Международные праздники)/ui', $name)) continue;
                if (mb_strlen($name, 'UTF-8') < 4) continue;
                if (!in_array($name, $holidays, true)) $holidays[] = $name;
            }
        }
    }
}

if (!$holidays) {
    // Fallback parser for hosts where the DOM extension is unavailable or markup differs.
    $plain = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', "\n", $html);
    $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES, 'UTF-8');
    $plain = str_replace("\xC2\xA0", ' ', $plain);
    $plain = preg_replace('/[ \t]+/u', ' ', $plain);
    $pattern = '/' . preg_quote($heading, '/') . '[^\r\n]*[\r\n]+([^\r\n]+)/u';
    if (preg_match($pattern, $plain, $match)) {
        $candidate = trim($match[1]);
        if ($candidate !== '' && mb_strlen($candidate, 'UTF-8') > 3) $holidays[] = $candidate;
    }
}

if (!$holidays) {
    respond(false, array(), 'Страница получена, но названия праздников не найдены. Проверьте расширение PHP DOM на хостинге.');
}

respond(true, array_slice($holidays, 0, 4), null);
