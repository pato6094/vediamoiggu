<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['logged'])) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

include 'connessione.php';

$lastId = isset($_GET['lastId']) ? intval($_GET['lastId']) : 0;

$query = $conn->prepare("
    SELECT MAX(id) as newLastId, 
           COUNT(*) as newCount 
    FROM prenotazioni 
    WHERE id > ? OR 
          (id <= ? AND stato = 'Cancellata' AND last_updated > NOW() - INTERVAL 5 SECOND)
");
$query->bind_param("ii", $lastId, $lastId);
$query->execute();
$result = $query->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $hasNew = ($row['newCount'] > 0);
    echo json_encode([
        'success' => true,
        'hasNewBookings' => $hasNew,
        'newLastId' => $row['newLastId'] ?: $lastId
    ]);
} else {
    echo json_encode(['success' => false]);
}
?>
