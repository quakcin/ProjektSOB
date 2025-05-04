<?php

if ($_SERVER['REQUEST_URI'] === "/getlog") {
	include "./log.php";
	exit;
}

function log_msg ($msg)
{
	file_put_contents("./log.txt", "[" . date('Y-m-d G:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] === "POST" && $_POST['action'] == "clear_log") {
	file_put_contents("./log.txt", "");
}

if ($_SERVER['REQUEST_METHOD'] === "POST" && $_POST['action'] == "log") {
	log_msg($_POST['msg']);
	exit;
}

function cmd_broadcast ($cmd)
{
	for ($i = 1; $i <= 8; $i++) {
		cmd_send($cmd, $i);
	}
}

function cmd_send ($cmd, $serverId)
{
	$url = "http://php{$serverId}:800{$serverId}";
	
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($cmd));
	curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
	curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

	$response = curl_exec($ch);

	curl_close($ch);
}

?>

<h2>graf SERWERÓW</h2>

<?php

	/**
	 * Zarządzanie grafem serwerów
	 */

	function convert_post_inputs_to_server_graph ()
	{
		$graph = [];

		foreach ($_POST as $key => $val) {
			if (strpos($key, "connect_") === 0) {
				$pos = explode("_", $key);
				$pos = explode("x", $pos[1]);

				if (!isset($graph[$pos[0]])) {
					$graph[$pos[0]] = [];
				}

				$graph[$pos[0]][] = $pos[1];
			}
		}

		return (object) $graph;
	}

	function store_graph_configuration ($graph)
	{
		file_put_contents("./graph.json", json_encode($graph));
	}

	function load_graph_configuration ()
	{
		return json_decode(file_get_contents("./graph.json"));
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] == "graf") {
		$graph = convert_post_inputs_to_server_graph();
		store_graph_configuration($graph);
		cmd_broadcast([
			"action" => "graf",
			"graf" => $graph,
		]);
		log_msg("Zakutalizowano strukturę grafu");
	}

	$graph = load_graph_configuration();

?>

<form action="/" method="POST">
	<input type="hidden" name="action" value="graf" />
	<table>
	<? for ($i = 0; $i < 8; $i++): ?>
		<tr>
		<? for ($j = 0; $j < 8; $j++): ?>
			<? if ($i == 0 && $j == 0): ?>
				<td>S/S</td>
			<? elseif ($i == 0 && $j >= 1): ?>
				<td>S<?= $j?></td>
			<? elseif ($i >= 1 && $j == 0): ?>
				<td>S<?= $i?></td>
			<? elseif ($i == $j || $j < $i): ?>
				<td>
					<input type="checkbox" disabled />
				</td>
			<? else: ?>
				<td>
					<?php
						$name = "connect_{$i}x{$j}";
						$checked = isset($graph->{$i}) && in_array($j, $graph->{$i})
							? "checked" : "";

					?>
					<input type="checkbox" name="<?= $name ?>" <?= $checked ?>/>
				</td>
			<? endif ?>
		<? endfor; ?>
		</tr>
	<? endfor; ?>
	</table>
	<button>
		Aktualizuj
	</button>
</form>

<hr/> <h3>Wysłanie komunikatu</h3>

<?php
	/**
	 * Wysyłanie komunikatów:
	 */
	
	if ($_SERVER['REQUEST_METHOD'] === "POST" && $_POST['action'] === "komunikat") {
		
		$hamming = [
			[0, 0, 0, 0],
			[0, 0, 0, 0],
			[0, 0, 0, 0],
			[0, 0, 0, 0],
		];

		for ($i = 0; $i < 4; $i++) {
			for ($j = 0; $j < 4; $j++) {
				$hamming[$i][$j] = isset($_POST["bit_{$i}x{$j}"]) ? 1 : 0;
			}
		}

		/**
		 * 16bitowa macierz
		 * 
		 * x P x B
		 * x B x B
		 * x B x B
		 * x B x B

		 * x x P B
		 * x x B B
		 * x x B B
		 * x x B B

		 * x x x x
		 * P b b b
		 * x x x x
		 * b b b b

		 * x x x x
		 * x x x x
		 * P b b b
		 * b b b b
		 */

		for ($i = 0; $i < 4; $i++) {
			$hamming[0][1] += $hamming[$i][1] + $hamming[$i][3];
			$hamming[0][2] += $hamming[$i][2] + $hamming[$i][3];

			$hamming[1][0] += $hamming[1][$i] + $hamming[3][$i];
			$hamming[2][0] += $hamming[2][$i] + $hamming[3][$i];
		}

		$hamming[0][1] %= 2;
		$hamming[0][2] %= 2;

		$hamming[1][0] %= 2;
		$hamming[2][0] %= 2;


		for ($i = 0; $i < 4; $i++) {
			for ($j = 0; $j < 4; $j++) {
				$hamming[0][0] += $hamming[$i][$j];
			}
		}

		$hamming[0][0] %= 2;


		/**
		 * Podgląd macierzy z ustaiwonymi bitami parzystości
		 */

		echo "<pre>Macierz:\n";
		for ($i = 0; $i < 4; $i++) {
			for ($j = 0; $j < 4; $j++) {
				echo $hamming[$i][$j] . " ";
			}
			echo "\n";
		}

		echo "</pre>";


		/**
		 * Wysłanie polecenia serwerowi
		 */

		$msg = "";
		for ($i = 0; $i < 4; $i++) {
			for ($j = 0; $j < 4; $j++) {
				$msg .= $hamming[$i][$j] ? "1" : "0";
			}
		}

		log_msg("Wysyłanie komunikatu z S{$_POST['from']} do S{$_POST['to']}: {$msg}");

		cmd_send([
			"action" => "komunikat",
			"to" => (int) $_POST['to'],
			"msg" => $msg
		], $_POST['from']);
	}
?>

<form action="/" method="POST">
	<input type="hidden" name="action" value="komunikat" />

	Od: 
	<select name="from">
		<? for ($i = 1; $i <= 8; $i++): ?>
			<option value="<?= $i ?>">S<?= $i ?></option>
		<? endfor ?>
	</select>
	Do: 
	<select name="to">
		<? for ($i = 1; $i <= 8; $i++): ?>
			<option value="<?= $i ?>">S<?= $i ?></option>
		<? endfor ?>
	</select>

	Kod: <br/>

	<table>
	<? for ($i = 0; $i < 4; $i++): ?>
		<tr>
		<? for ($j = 0; $j < 4; $j++): ?>
			<td>
				<?php
					$disabled = (($i == 0 && $j <= 2) || ($j == 0 && $i <= 2))
						? "disabled" : "";
				?>
				<input type="checkbox" name="bit_<?= $i ?>x<?= $j ?>" <?= $disabled ?> />
			</td>
		<? endfor ?>
		</tr>
	<? endfor ?>
	</table>

	<button>Wyślij</button>
</form>


<hr/> <h3>Log</h3>

<iframe src="/getlog" style="width: 100%; height: 500px"></iframe>

<form action="/" method="POST">
	<input type="hidden" name="action" value="clear_log" />
	<button>Wyczyść</button>
</form>