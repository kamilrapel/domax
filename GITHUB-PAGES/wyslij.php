<?php
/**
 * DOMAX - odbiór zgłoszeń z formularza wyceny.
 *
 * Plik wgrywamy razem z resztą strony do katalogu głównego domeny.
 * Wymaga hostingu z PHP (każdy hosting współdzielony go ma).
 * Nie wymaga bazy danych, konta w zewnętrznej usłudze ani opłat.
 *
 * KONFIGURACJA: jedyne, co trzeba sprawdzić, to dwie linie niżej.
 */

// --- konfiguracja -----------------------------------------------------------
$ODBIORCA   = 'kontakt@rolety-domax.pl';        // dokąd trafiają zgłoszenia
$NADAWCA    = 'formularz@rolety-domax.pl';      // musi być adresem w tej domenie,
                                                // inaczej poczta trafi do spamu
$KOPIA_DO   = '';                               // opcjonalnie drugi adres, np. komórka
// ----------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

function odpowiedz($ok, $komunikat, $kod = 200) {
    http_response_code($kod);
    echo json_encode(['ok' => $ok, 'komunikat' => $komunikat], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    odpowiedz(false, 'Nieprawidłowe żądanie.', 405);
}

// --- ochrona przed botami ---------------------------------------------------
// 1. pułapka: pole ukryte przed człowiekiem, bot je wypełni
if (!empty($_POST['firma_www'])) {
    odpowiedz(true, 'Dziękujemy za zgłoszenie.');   // udajemy sukces, nic nie wysyłamy
}
// 2. czas: formularz wypełniony w mniej niż 3 sekundy to robot
$czas = isset($_POST['czas_startu']) ? (int) $_POST['czas_startu'] : 0;
if ($czas > 0 && (time() * 1000 - $czas) < 3000) {
    odpowiedz(true, 'Dziękujemy za zgłoszenie.');
}

// --- dane -------------------------------------------------------------------
function pole($nazwa, $max = 500) {
    $v = isset($_POST[$nazwa]) ? trim((string) $_POST[$nazwa]) : '';
    $v = str_replace(["\r", "\n"], ' ', $v);          // blokada wstrzykiwania nagłówków
    return mb_substr($v, 0, $max);
}

$imie     = pole('imie', 120);
$telefon  = pole('telefon', 40);
$email    = pole('email', 160);
$miasto   = pole('miasto', 120);
$budynek  = pole('budynek', 80);

// --- wymiary poszczególnych okien -------------------------------------------
// Formularz wysyła trzy równoległe tablice: opis pomieszczenia, szerokość i wysokość.
// Bierzemy tylko te wiersze, w których klient cokolwiek wpisał.
function tablica($nazwa) {
    return isset($_POST[$nazwa]) && is_array($_POST[$nazwa]) ? array_values($_POST[$nazwa]) : [];
}
function wymiar($v) {
    $n = (int) preg_replace('/\D/', '', (string) $v);
    return ($n >= 10 && $n <= 900) ? $n : 0;
}

$o_opis  = tablica('okno_opis');
$o_typ   = tablica('okno_typ');
$o_kolor = tablica('okno_kolor');
$o_szer  = tablica('okno_szer');
$o_wys   = tablica('okno_wys');

$okna  = [];
$ile   = min(max(count($o_opis), count($o_typ), count($o_szer), count($o_wys)), 20);
for ($i = 0; $i < $ile; $i++) {
    $opisO  = mb_substr(str_replace(["\r", "\n"], ' ', trim((string) ($o_opis[$i] ?? ''))), 0, 60);
    $typO   = mb_substr(str_replace(["\r", "\n"], ' ', trim((string) ($o_typ[$i] ?? ''))), 0, 60);
    $kolorO = trim((string) ($o_kolor[$i] ?? ''));
    $kolorO = in_array($kolorO, ['Jednokolorowy', 'Drewnopodobny'], true) ? $kolorO : '';
    $sz     = wymiar($o_szer[$i] ?? '');
    $wy     = wymiar($o_wys[$i] ?? '');
    if ($opisO === '' && $typO === '' && $kolorO === '' && !$sz && !$wy) {
        continue;                                  // pusty wiersz - pomijamy
    }
    $wymiary = ($sz && $wy) ? "$sz x $wy cm" : (($sz || $wy) ? ($sz ? "szer. $sz cm" : "wys. $wy cm") : 'wymiary nie podane');
    $okna[]  = ($opisO !== '' ? $opisO : 'okno ' . ($i + 1))
        . ' | ' . ($typO !== '' ? $typO : 'rodzaj nie wskazany')
        . ' | ' . $wymiary
        . ($kolorO !== '' ? ' | osprzęt ' . mb_strtolower($kolorO) : '');
}

$opis     = mb_substr(trim((string) ($_POST['opis'] ?? '')), 0, 2000);
$produkty = isset($_POST['produkt']) ? (array) $_POST['produkt'] : [];
$produkty = array_map(function ($p) { return mb_substr(trim($p), 0, 80); }, $produkty);
$zgoda    = !empty($_POST['zgoda']);

// --- walidacja --------------------------------------------------------------
$bledy = [];
if (mb_strlen($imie) < 3)                        $bledy[] = 'imię i nazwisko';
if (preg_match_all('/\d/', $telefon) < 9)        $bledy[] = 'numer telefonu';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $bledy[] = 'adres e-mail';
if (!$zgoda)                                     $bledy[] = 'zgoda na kontakt';

if ($bledy) {
    odpowiedz(false, 'Uzupełnij: ' . implode(', ', $bledy) . '.', 422);
}

// --- treść wiadomości -------------------------------------------------------
$tresc  = "Nowe zgłoszenie ze strony rolety-domax.pl\n";
$tresc .= str_repeat('=', 46) . "\n\n";
$tresc .= "Imię i nazwisko : $imie\n";
$tresc .= "Telefon         : $telefon\n";
$tresc .= "E-mail          : " . ($email !== '' ? $email : 'nie podano') . "\n";
$tresc .= "Miejscowość     : " . ($miasto !== '' ? $miasto : 'nie podano') . "\n\n";
$tresc .= "Rodzaj budynku  : $budynek\n";
$tresc .= "Interesuje go   : " . ($produkty ? implode(', ', $produkty) : 'nie wskazano') . "\n\n";
if ($okna) {
    $tresc .= 'Okna (' . count($okna) . "), opisane przez klienta:\n";
    $tresc .= "  pomieszczenie | rodzaj osłony | wymiary | kolor osprzętu\n";
    foreach ($okna as $nr => $w) {
        $tresc .= '  ' . ($nr + 1) . '. ' . $w . "\n";
    }
    $tresc .= "\n";
} else {
    $tresc .= "Okna            : klient nie podał wymiarów\n\n";
}
if ($opis !== '') {
    $tresc .= "Opis sytuacji:\n$opis\n\n";
}
$tresc .= str_repeat('-', 46) . "\n";
$tresc .= 'Wysłano: ' . date('d.m.Y H:i') . "\n";
$tresc .= 'Adres IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'nieznany') . "\n";
$tresc .= "Klient wyraził zgodę na kontakt telefoniczny lub mailowy.\n";

$temat = 'Wycena: ' . $imie . ($miasto !== '' ? ", $miasto" : '');

$naglowki = [
    'From: DOMAX formularz <' . $NADAWCA . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: PHP/' . phpversion(),
];
if ($email !== '') {
    $naglowki[] = 'Reply-To: ' . $email;   // odpowiedź poleci prosto do klienta
}
if ($KOPIA_DO !== '') {
    $naglowki[] = 'Cc: ' . $KOPIA_DO;
}

$wyslano = @mail(
    $ODBIORCA,
    '=?UTF-8?B?' . base64_encode($temat) . '?=',
    $tresc,
    implode("\r\n", $naglowki),
    '-f' . $NADAWCA
);

/**
 * Zapis awaryjny - używany tylko wtedy, gdy poczta odmówi współpracy,
 * żeby żadne zgłoszenie nie przepadło.
 *
 * UWAGA: ten plik zawiera dane osobowe klientów, więc NIE MOŻE być możliwy
 * do pobrania z przeglądarki. Zabezpieczamy go na trzy sposoby:
 *  1. najpierw próbujemy katalogu POWYŻEJ katalogu strony - tam serwer WWW nie sięga,
 *  2. jeśli się nie da, zapisujemy plik z rozszerzeniem .php, którego pierwsza linia
 *     natychmiast kończy działanie i zwraca 404 - po wpisaniu adresu nie widać nic,
 *  3. dodatkowo blokuje go reguła w pliku .htaccess.
 */
function zapis_awaryjny($tresc) {
    $naglowek = "<?php http_response_code(404); exit; ?>\n";
    $miejsca  = [
        dirname(__DIR__) . '/domax-zgloszenia-awaryjne.php',   // poza katalogiem strony
        __DIR__ . '/zgloszenia-awaryjne.php',                  // ostatnia deska ratunku
    ];
    foreach ($miejsca as $sciezka) {
        $nowy = !file_exists($sciezka);
        if ($nowy && !is_writable(dirname($sciezka))) {
            continue;
        }
        $dane = ($nowy ? $naglowek : '') . $tresc . "\n\n";
        if (@file_put_contents($sciezka, $dane, FILE_APPEND | LOCK_EX) !== false) {
            @chmod($sciezka, 0600);                            // czyta tylko właściciel
            return true;
        }
    }
    return false;
}

if (!$wyslano) {
    zapis_awaryjny($tresc);
    odpowiedz(false, 'Nie udało się wysłać wiadomości. Zadzwoń: 690 120 170.', 500);
}

odpowiedz(true, 'Dziękujemy. Oddzwonimy w godzinach pracy, żeby umówić bezpłatny pomiar.');
