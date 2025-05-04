<?php
$serverId = ((int) $_SERVER['SERVER_PORT']) - 8000;

function cmd_send ($cmd, $serverId)
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


/**
 * Próba wysłania koumnikatu, musimy:
 * - sprawdzić czy jesteśmy odbiorcą
 * - czy mamy trasę
 * - zasymulować błąd (jeśli ustawiony)
 * - przekazać dalej
 */
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === "komunikat") {

    /**
     * @TODO: Sprawdzanie i korekcja jeśli nastąpił błąd
     */

    if ($_POST['to'] == $serverId) {
        /** Jesteśmy odbiorcą! */
        log_central("Komunikat dotarł do odbiorcy: " . $_POST['msg']);
        exit;
    }

    $route = route_graph($_POST['to']);

    if ($route == null) {
        /** Droga nie istnieje */
        log_central("Droga do S{$_POST['to']} przez S{$serverId} nie istnieje!");
        exit;
    }

    /** @TODO: Symulacja błędu na podstawie ustawień */
    if ($route != null) {
        /** Przekazujemy komunikat do kolejnego serwera */
        log_central("Przekazuje komunikat do: S{$route}");
        cmd_send([
            "action" => "komunikat",
            "msg" => $_POST['msg'],
            "to" => $_POST['to']
        ], (int) $route);
    }

    exit;
}
