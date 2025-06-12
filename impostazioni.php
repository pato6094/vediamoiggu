<?php
session_start();
if (!isset($_SESSION['logged'])) {
    header("Location: login.php");
    exit();
}
include 'connessione.php';

// Handle password change
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = "Tutti i campi sono obbligatori.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "Le nuove password non coincidono.";
    } elseif (strlen($new_password) < 6) {
        $error_message = "La nuova password deve essere di almeno 6 caratteri.";
    } else {
        // Get current admin password
        $stmt = $conn->prepare("SELECT id, password FROM admin LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $error_message = "Admin non trovato.";
        } else {
            $admin = $result->fetch_assoc();
            
            // Verify current password
            if (!password_verify($current_password, $admin['password'])) {
                $error_message = "Password attuale non corretta.";
            } else {
                // Hash new password and update
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE admin SET password = ? WHERE id = ?");
                $update_stmt->bind_param("si", $new_password_hash, $admin['id']);
                
                if ($update_stmt->execute()) {
                    $success_message = "Password cambiata con successo!";
                } else {
                    $error_message = "Errore durante il cambio password.";
                }
                $update_stmt->close();
            }
        }
        $stmt->close();
    }
}

// Get system information
$system_info = [
    'php_version' => phpversion(),
    'mysql_version' => $conn->server_info,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
    'max_upload_size' => ini_get('upload_max_filesize'),
    'max_execution_time' => ini_get('max_execution_time') . 's',
    'memory_limit' => ini_get('memory_limit')
];

// Get database statistics
$stats = [];
$tables = ['prenotazioni', 'servizi', 'operatori', 'admin'];
foreach ($tables as $table) {
    $result = $conn->query("SELECT COUNT(*) as count FROM $table");
    if ($result) {
        $row = $result->fetch_assoc();
        $stats[$table] = $row['count'];
    } else {
        $stats[$table] = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Impostazioni - Old School Barber</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);
            color: #ffffff;
            min-height: 100vh;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: #d4af37;
            border: none;
            border-radius: 8px;
            color: #1a1a2e;
            padding: 0.8rem;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .mobile-menu-btn:hover {
            background: #ffd700;
            transform: scale(1.05);
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 80px;
            height: 100vh;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            padding: 2rem 0;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .sidebar.expanded {
            width: 280px;
        }

        .sidebar-header {
            padding: 0 1.5rem;
            margin-bottom: 3rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            justify-content: center;
        }

        .sidebar.expanded .sidebar-header {
            padding: 0 2rem;
            justify-content: flex-start;
        }

        .sidebar-logo {
            font-size: 2rem;
            color: #d4af37;
        }

        .sidebar-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #ffffff;
            transition: opacity 0.3s ease;
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .sidebar.expanded .sidebar-title {
            opacity: 1;
            width: auto;
        }

        .sidebar-toggle {
            position: absolute;
            top: 1rem;
            right: -15px;
            width: 30px;
            height: 30px;
            background: #d4af37;
            border: none;
            border-radius: 50%;
            color: #1a1a2e;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }

        .sidebar-toggle:hover {
            background: #ffd700;
            transform: scale(1.1);
        }

        .sidebar-nav {
            list-style: none;
            padding: 0 1rem;
        }

        .sidebar-nav li {
            margin-bottom: 0.5rem;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 0.5rem;
            color: #a0a0a0;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 500;
            justify-content: center;
        }

        .sidebar.expanded .sidebar-nav a {
            padding: 1rem;
            justify-content: flex-start;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(212, 175, 55, 0.1);
            color: #d4af37;
            transform: translateX(5px);
        }

        .sidebar-nav i {
            font-size: 1.2rem;
            width: 20px;
            text-align: center;
        }

        .sidebar-nav span {
            display: none;
        }

        .sidebar.expanded .sidebar-nav span {
            display: inline;
        }

        /* Main Content */
        .main {
            margin-left: 80px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .main.expanded {
            margin-left: 280px;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 2rem;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #ffffff;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            background: rgba(212, 175, 55, 0.1);
            color: #d4af37;
            text-decoration: none;
            border-radius: 12px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(212, 175, 55, 0.2);
            transform: translateY(-2px);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .content-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .content-card h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .content-card h3 i {
            color: #d4af37;
        }

        /* Form Styles */
        .form-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .form-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .form-card.password-change {
            border-color: rgba(212, 175, 55, 0.3);
            background: rgba(212, 175, 55, 0.05);
        }

        .form-card h3 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #ffffff;
        }

        .form-card.password-change h3 {
            color: #d4af37;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #e0e0e0;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group input {
            width: 100%;
            padding: 0.8rem 1rem;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            color: #ffffff;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #d4af37;
            background: rgba(255, 255, 255, 0.12);
        }

        .form-group input::placeholder {
            color: #a0a0a0;
        }

        .submit-btn {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #d4af37, #ffd700);
            color: #1a1a2e;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .info-card i {
            font-size: 2rem;
            color: #d4af37;
            margin-bottom: 1rem;
        }

        .info-card h4 {
            font-size: 1rem;
            font-weight: 600;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }

        .info-card p {
            color: #a0a0a0;
            font-size: 0.9rem;
        }

        /* Stats Table */
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .stats-table th,
        .stats-table td {
            padding: 1rem 0.8rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .stats-table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: #d4af37;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stats-table td {
            color: #e0e0e0;
            font-weight: 400;
            font-size: 0.9rem;
        }

        /* Messages */
        .error-message, .success-message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            text-align: center;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        .success-message {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #4ade80;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .form-section {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
                padding: 1rem;
                padding-top: 4rem;
            }

            .main.expanded {
                margin-left: 0;
            }

            .header {
                padding: 1rem;
                flex-direction: column;
                text-align: center;
            }

            .header-title {
                font-size: 1.4rem;
            }

            .content-card {
                padding: 1.5rem;
            }

            .form-card {
                padding: 1.5rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .main {
                padding: 0.5rem;
                padding-top: 3.5rem;
            }
            
            .header {
                padding: 0.8rem;
                margin-bottom: 1rem;
            }
            
            .header-title {
                font-size: 1.2rem;
            }
            
            .content-card {
                padding: 1rem;
            }
            
            .content-card h3 {
                font-size: 1.1rem;
            }
            
            .form-card {
                padding: 1rem;
            }
            
            .form-card h3 {
                font-size: 1rem;
            }
            
            .info-card {
                padding: 1rem;
            }
            
            .stats-table th,
            .stats-table td {
                padding: 0.6rem 0.3rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 360px) {
            .main {
                padding: 0.3rem;
                padding-top: 3rem;
            }
            
            .header-title {
                font-size: 1.1rem;
            }
            
            .form-group input {
                padding: 0.6rem 0.8rem;
                font-size: 0.85rem;
            }
            
            .submit-btn {
                padding: 0.8rem;
                font-size: 0.85rem;
            }
        }

        /* Touch device optimizations */
        @media (hover: none) and (pointer: coarse) {
            .submit-btn {
                min-height: 44px;
                min-width: 44px;
            }
            
            .sidebar-toggle {
                width: 40px;
                height: 40px;
            }
            
            .mobile-menu-btn {
                padding: 1rem;
                min-height: 44px;
                min-width: 44px;
            }
        }

        /* Accessibility improvements */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>

<button class="mobile-menu-btn" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
</button>

<div class="sidebar" id="sidebar">
    <button class="sidebar-toggle" onclick="toggleSidebar()">
        <i class="fas fa-chevron-right"></i>
    </button>
    
    <div class="sidebar-header">
        <i class="fas fa-cut sidebar-logo"></i>
        <span class="sidebar-title">Admin Panel</span>
    </div>
    
    <ul class="sidebar-nav">
        <li><a href="admin.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
        <li><a href="gestione_prenotazioni.php"><i class="fas fa-calendar-alt"></i><span>Prenotazioni</span></a></li>
        <li><a href="gestione_operatori.php"><i class="fas fa-scissors"></i><span>Operatori</span></a></li>
        <li><a href="impostazioni.php" class="active"><i class="fas fa-cog"></i><span>Impostazioni</span></a></li>
        <li><a href="index.php"><i class="fas fa-arrow-left"></i><span>Torna al sito</span></a></li>
    </ul>
</div>

<div class="main" id="main">
    <div class="header">
        <h1 class="header-title">Impostazioni Sistema</h1>
        <a href="admin.php" class="back-btn">
            <i class="fas fa-arrow-left"></i>
            Torna al Dashboard
        </a>
    </div>

    <?php if ($success_message): ?>
        <div class="success-message"><?php echo $success_message; ?></div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="error-message"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <div class="form-section">
        <div class="form-card password-change">
            <h3><i class="fas fa-key"></i>Cambia Password Admin</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="current_password">Password Attuale</label>
                    <input type="password" name="current_password" id="current_password" placeholder="Inserisci la password attuale" required>
                </div>
                <div class="form-group">
                    <label for="new_password">Nuova Password</label>
                    <input type="password" name="new_password" id="new_password" placeholder="Inserisci la nuova password" required minlength="6">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Conferma Nuova Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Conferma la nuova password" required minlength="6">
                </div>
                <button type="submit" name="change_password" class="submit-btn">
                    <i class="fas fa-save"></i> Cambia Password
                </button>
            </form>
        </div>
    </div>

    <div class="content-grid">
        <div class="content-card">
            <h3><i class="fas fa-info-circle"></i>Informazioni Sistema</h3>
            <div class="info-grid">
                <div class="info-card">
                    <i class="fas fa-code"></i>
                    <h4>Versione PHP</h4>
                    <p><?php echo $system_info['php_version']; ?></p>
                </div>
                <div class="info-card">
                    <i class="fas fa-database"></i>
                    <h4>Versione MySQL</h4>
                    <p><?php echo $system_info['mysql_version']; ?></p>
                </div>
                <div class="info-card">
                    <i class="fas fa-server"></i>
                    <h4>Server</h4>
                    <p><?php echo $system_info['server_software']; ?></p>
                </div>
                <div class="info-card">
                    <i class="fas fa-upload"></i>
                    <h4>Max Upload</h4>
                    <p><?php echo $system_info['max_upload_size']; ?></p>
                </div>
                <div class="info-card">
                    <i class="fas fa-clock"></i>
                    <h4>Max Execution</h4>
                    <p><?php echo $system_info['max_execution_time']; ?></p>
                </div>
                <div class="info-card">
                    <i class="fas fa-memory"></i>
                    <h4>Memory Limit</h4>
                    <p><?php echo $system_info['memory_limit']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="content-grid">
        <div class="content-card">
            <h3><i class="fas fa-chart-bar"></i>Statistiche Database</h3>
            <table class="stats-table">
                <thead>
                    <tr>
                        <th>Tabella</th>
                        <th>Record</th>
                        <th>Descrizione</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Prenotazioni</td>
                        <td><?php echo $stats['prenotazioni']; ?></td>
                        <td>Totale prenotazioni nel sistema</td>
                    </tr>
                    <tr>
                        <td>Servizi</td>
                        <td><?php echo $stats['servizi']; ?></td>
                        <td>Servizi disponibili</td>
                    </tr>
                    <tr>
                        <td>Operatori</td>
                        <td><?php echo $stats['operatori']; ?></td>
                        <td>Operatori registrati</td>
                    </tr>
                    <tr>
                        <td>Admin</td>
                        <td><?php echo $stats['admin']; ?></td>
                        <td>Account amministratori</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let sidebarCollapsed = true;
let mobileOpen = false;

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const main = document.getElementById('main');
    const toggleIcon = document.querySelector('.sidebar-toggle i');
    const isMobile = window.innerWidth <= 768;

    if (isMobile) {
        mobileOpen = !mobileOpen;
        sidebar.classList.toggle('mobile-open');
    } else {
        sidebarCollapsed = !sidebarCollapsed;
        sidebar.classList.toggle('expanded');
        main.classList.toggle('expanded');
        toggleIcon.className = sidebarCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
    }
}

// Password validation
document.getElementById('confirm_password').addEventListener('input', function() {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = this.value;
    
    if (newPassword !== confirmPassword) {
        this.setCustomValidity('Le password non coincidono');
    } else {
        this.setCustomValidity('');
    }
});

document.getElementById('new_password').addEventListener('input', function() {
    const confirmPassword = document.getElementById('confirm_password');
    if (confirmPassword.value) {
        confirmPassword.dispatchEvent(new Event('input'));
    }
});

// Close mobile sidebar when clicking outside
document.addEventListener('click', (e) => {
    const sidebar = document.getElementById('sidebar');
    const mobileBtn = document.querySelector('.mobile-menu-btn');
    
    if (window.innerWidth <= 768 && mobileOpen && 
        !sidebar.contains(e.target) && 
        !mobileBtn.contains(e.target)) {
        toggleSidebar();
    }
});

// Handle window resize
window.addEventListener('resize', () => {
    const sidebar = document.getElementById('sidebar');
    
    if (window.innerWidth > 768) {
        sidebar.classList.remove('mobile-open');
        mobileOpen = false;
    }
});

// Touch device optimizations
if ('ontouchstart' in window) {
    document.body.classList.add('touch-device');
}

// Viewport height fix for mobile browsers
function setViewportHeight() {
    const vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', `${vh}px`);
}

setViewportHeight();
window.addEventListener('resize', setViewportHeight);
window.addEventListener('orientationchange', () => {
    setTimeout(setViewportHeight, 100);
});
</script>
</body>
</html>
```