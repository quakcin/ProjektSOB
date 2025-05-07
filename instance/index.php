<?php
$serverId = ((int) $_SERVER['SERVER_PORT']) - 8000;

function cmd_send ($cmd, $serverId, $onFail = null)
{
    $name = $serverId == "0" ? "central" : "php{$serverId}";

	$url = "http://{$name}:800{$serverId}";
	
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($cmd));
	curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
	curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

	$response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($serverId !== 0 && $status !== 200 && $onFail != null) {
        $onFail->__invoke();
    }

	curl_close($ch);
}

function store_graph_configuration ($graph)
{
    file_put_contents("/etc/graph.json", json_encode($graph));
}

function load_graph_configuration ()
{
    $graph = (array) json_decode(file_get_contents("/etc/graph.json"));

    foreach ($graph as $key => $arr) {
        foreach ($arr as $val) {
            if (!isset($graph[$val])) {
                $graph[$val] = [];
            }

            if (!in_array($key, $graph[$val])) {
                $graph[$val][] = $key;
            }
        }
    }

    return $graph;
}

if ($_SERVER['REQUEST_METHOD'] === "POST" && $_POST['action'] === "graf") {
    store_graph_configuration($_POST['graf']);
    exit;
}

$graph = load_graph_configuration();


function log_central ($msg)
{
    global $serverId;
    cmd_send([
        "action" => "log",
        "msg" => "[S{$serverId}] " . $msg
    ], 0);
}



$visited = [];

function route_graph_traverse ($ptr, $dest)
{
    global $graph;
    global $visited;
    $visited[] = $ptr;

    foreach ($graph[$ptr] as $edge) {

        if ($edge == $dest) {
            return $edge;
        }

        if (in_array($edge, $visited)) {
            /** już odwiedzony */
            continue;
        }

        if (route_graph_traverse($edge, $dest) != null) {
            return $edge;
        }
    }

    return null;
}

function route_graph ($dest)
{
    global $graph;
    global $serverId;
    return route_graph_traverse($serverId, $dest);
}

function msg_pp ($msg)
{
    return substr($msg, 0, 4) . " " . substr($msg, 4, 4) . " " . substr($msg, 8, 4) . " " . substr($msg, 12, 4);
}


function store_toggler ($bits, $is_disabled)
{
    file_put_contents("/etc/toggler.json", json_encode(["bits" => $bits, "is_disabled" => $is_disabled]));
}

function load_toggler ()
{
    return json_decode(file_get_contents("/etc/toggler.json"));
}

/**
 * Flipuje w $msg bity na pozycjach z $bits
 */
function toggler_sim ($msg, $bits)
{
    for ($i = 0; $i < 16; $i++) {
        if ($bits[$i] == '1') {
            $msg[$i] = ($msg[$i] == '1') ? '0' : '1';
        }
    }

    return $msg;
}


/**
 * Sprawdzamy czy bity parzystości się zgadzają
 */
function hamming_check ($msg)
{
    /** OMP */
    $omp = 0;
    for ($i = 0; $i < 16; $i++) {
        $omp += $msg[$i] == '1' ? 1 : 0;
    }
    $omp %= 2;

    /**
     * Macierz
     */

    $mx = [
        [0, 0, 0, 0],
        [0, 0, 0, 0],
        [0, 0, 0, 0],
        [0, 0, 0, 0]
    ];

    for ($i = 0; $i < 4; $i++) {
        for ($j = 0; $j < 4; $j++) {
            $mx[$i][$j] = ($msg[$i * 4 + $j] == '1') ? 1 : 0;
        }
    }

    /**
     * Liczenie bitów parzystości, i sprawdzenie z orginalnymi
     * oraz z dodatkowym w celu określenia ilości błędów, i 
     * ewentualnej możliwości korekty
     */

    $op1 = $mx[0][1];
    $op2 = $mx[0][2];
    $op4 = $mx[1][0];
    $op8 = $mx[2][0];

    $mx[0][1] = 0;
    $mx[0][2] = 0;
    $mx[1][0] = 0;
    $mx[2][0] = 0;

    $p1 = 0;
    $p2 = 0;
    $p4 = 0;
    $p8 = 0;

    for ($i = 0; $i < 4; $i++) {
        $p1 += $mx[$i][1] + $mx[$i][3];
        $p2 += $mx[$i][2] + $mx[$i][3];

        $p4 += $mx[1][$i] + $mx[3][$i];
        $p8 += $mx[2][$i] + $mx[3][$i];
    }

    $p1 %= 2;
    $p2 %= 2;
    $p4 %= 2;
    $p8 %= 2;

    $areParityBitsCorrect = ($p1 == $op1 && $p2 == $op2 && $p4 == $op4 && $p8 == $op8);
    $isOveralParityCorrect = $omp == 0;

    /** Nie da się poprawić, dwa błędy */
    if (!$areParityBitsCorrect && $isOveralParityCorrect) {
        log_central("Wykryto błąd podwójny, poddaje się!");
        return false;
    }

    /** Nie ma co porpawiać, parzystość się zgadza */
    if ($areParityBitsCorrect && $isOveralParityCorrect) {
        return $msg;
    }

    log_central("Wykryto błąd pojedyńczy!");

    /**
     * Jak weźmiemy {p8, p4, p2, p1}, to jest to
     * dosłownie index bitu który był flipnięty
     */


    $pos = "0000";

    if ($p1 != $op1) {
        $pos[3] = '1';
    }

    if ($p2 != $op2) {
        $pos[2] = '1';
    }

    if ($p4 != $op4) {
        $pos[1] = '1';
    }

    if ($p8 != $op8) {
        $pos[0] = '1';
    }

    $pos = bindec($pos);
    $msg[$pos] = ($msg[$pos] == '1') ? '0' : '1';

    log_central("Korekta: " . msg_pp($msg) . " , w pozycji: " . $pos);

    return $msg;
}

/**
 * Próba wysłania koumnikatu, musimy:
 * - sprawdzić czy jesteśmy odbiorcą
 * - czy mamy trasę
 * - zasymulować błąd (jeśli ustawiony)
 * - przekazać dalej
 */
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === "komunikat") {

    $toggler = load_toggler();

    /** Symulacja odłączenia serwera z grafu */
    if ($toggler->is_disabled) {
        exit(http_response_code(500));
    }

    $msg = $_POST['msg'];

    /** Sprawdzeneie czy komuniakt posiada błąd */
    $msg = hamming_check($msg);
    if ($msg === false) {
        exit;
    }

    if ($_POST['to'] == $serverId) {
        /** Jesteśmy odbiorcą! */
        log_central("Komunikat dotarł do odbiorcy: " . msg_pp($msg));
        exit;
    }

    $route = route_graph($_POST['to']);

    if ($route == null) {
        /** Droga nie istnieje */
        log_central("Droga do S{$_POST['to']} przez S{$serverId} nie istnieje!");
        exit;
    }

    log_central("Przekazuje komunikat: " . msg_pp($msg) . " do: S{$route}");

    /** Symulacja błędu na podstawie ustawień */
    $bits = $toggler->bits;
    
    if (strpos($bits, "1") !== false) {
        $msg = toggler_sim($msg, $bits);
        log_central("Symuluje błąd: " . msg_pp($msg) . " w: " . msg_pp($bits));
    }

    /** Przekazujemy komunikat do kolejnego serwera */
    cmd_send([
        "action" => "komunikat",
        "msg" => $msg,
        "to" => $_POST['to']
    ], (int) $route, function () use ($route) {
        log_central("Nie można nawiązać połączenia z S{$route}");
    });

    exit;
}

/**
 * Defaultowy widok instancji, czyli okno do edycji
 * konfiguracji symulowania błędów
 */

if ($_SERVER['REQUEST_METHOD'] === "POST" && $_POST['action'] === "set") {
    $bits = "";

    for ($i = 0; $i < 16; $i++) {
        $bits .= isset($_POST["bit_{$i}"]) ? "1" : "0";
    }

    store_toggler($bits, isset($_POST['is_disabled']));
    log_central("S{$serverId} przestawia bity: " . msg_pp($bits));
}

$toggler = load_toggler();
$bits = $toggler->bits;
$is_disabled = $toggler->is_disabled;

?>

<form action="/" method="POST">
	<input type="hidden" name="action" value="set" />

    (S<?= $serverId ?>) Zamień: 
    <? for ($i = 0; $i < 16; $i++): ?>
        <input type="checkbox" name="bit_<?= $i ?>" <?= $bits[$i] == "1" ? "checked" : "" ?> />

        <? if ($i % 4 == 3): ?>
            <span style="margin-left: 20px;"></span>
        <? endif ?>
    <? endfor ?>

    [
        <input type="checkbox" name="is_disabled" <?= $is_disabled ? 'checked' : '' ?> /> - Serwer Wyłączony
    ]

	<button>Ustaw</button>
</form>
