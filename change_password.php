<?php
session_start();
if (!isset($_SESSION['logged'])) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

include 'connessione.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Dati non validi']);
    exit();
}

$current_password = $input['current_password'] ?? '';
$new_password = $input['new_password'] ?? '';
$confirm_password = $input['confirm_password'] ?? '';

// Validate inputs
if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
    echo json_encode(['success' => false, 'message' => 'Tutti i campi sono obbligatori']);
    exit();
}

if ($new_password !== $confirm_password) {
    echo json_encode(['success' => false, 'message' => 'Le nuove password non coincidono']);
    exit();
}

if (strlen($new_password) < 6) {
    echo json_encode(['success' => false, 'message' => 'La nuova password deve essere di almeno 6 caratteri']);
    exit();
}

// Get current admin password (assuming there's only one admin)
$stmt = $conn->prepare("SELECT id, password FROM admin LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Admin non trovato']);
    exit();
}

$admin = $result->fetch_assoc();

// Verify current password
if (!password_verify($current_password, $admin['password'])) {
    echo json_encode(['success' => false, 'message' => 'Password attuale non corretta']);
    exit();
}

// Hash new password
$new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

// Update password
$update_stmt = $conn->prepare("UPDATE admin SET password = ? WHERE id = ?");
$update_stmt->bind_param("si", $new_password_hash, $admin['id']);

if ($update_stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Password cambiata con successo']);
} else {
    echo json_encode(['success' => false, 'message' => 'Errore durante il cambio password']);
}

$stmt->close();
$update_stmt->close();
$conn->close();
?>