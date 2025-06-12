<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['logged'])) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

include 'connessione.php';

// Query per ottenere tutte le prenotazioni
$query = "SELECT p.*, CONCAT(o.nome, ' ', o.cognome) as operatore_nome FROM prenotazioni p LEFT JOIN operatori o ON p.operatore_id = o.id ORDER BY p.data_prenotazione DESC, p.id DESC";
$result = $conn->query($query);

$prenotazioni = array();
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $prenotazioni[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode($prenotazioni);
?>
