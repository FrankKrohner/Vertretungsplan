<?php
session_start();

// Benutzerdaten einbinden
require_once 'secure/users.php';

// Funktion zur Überprüfung der Anmeldung
function isUserLoggedIn() {
    return isset($_SESSION['username']);
}

// Zugriffsschutz bei nicht-eingeloggtem Zustand
if (!isUserLoggedIn() && basename($_SERVER['PHP_SELF']) !== 'login.php') {
    header('Location: login.php');
    exit();
}

// Sicherheitsmaßnahmen gegen Session-Hijacking
if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
    session_destroy();
    header('Location: login.php');
    exit();
}

$error = '';
$login_attempts = 0;

// Rate Limiting - einfacher Schutz gegen Brute Force
if (isset($_SESSION['login_attempts'])) {
    $login_attempts = $_SESSION['login_attempts'];
}

// Login-Logik
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate Limiting: Maximal 5 Versuche pro Session
    if ($login_attempts >= 5) {
        $error = "Zu viele fehlgeschlagene Login-Versuche. Bitte warten Sie einige Minuten.";
    } else {
        // Input-Validierung und -Bereinigung
        $username = trim(htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8'));
        $password = $_POST['password'];
        
        // Leere Eingaben prüfen
        if (empty($username) || empty($password)) {
            $error = "Bitte geben Sie sowohl Benutzername als auch Passwort ein.";
            $login_attempts++;
        } else {
            // Benutzer validieren (verwendet die Funktionen aus users.php)
            if (verifyUser($username, $password)) {
                // Login erfolgreich
                $_SESSION['username'] = $username;
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                $_SESSION['user_type'] = getUserType($username);
                
                // Login-Versuche zurücksetzen
                unset($_SESSION['login_attempts']);
                
                // Weiterleitung basierend auf Benutzertyp
                $redirectUrl = getRedirectUrl($username);
                
                if ($redirectUrl) {
                    header("Location: " . $redirectUrl);
                    exit();
                } else {
                    $error = "Fehler beim Generieren der Weiterleitungs-URL.";
                }
            } else {
                $error = "Ungültige Anmeldedaten.";
                $login_attempts++;
            }
        }
        
        // Login-Versuche in Session speichern
        $_SESSION['login_attempts'] = $login_attempts;
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <link rel="stylesheet" href="index.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vertretungsplan SMG</title>
    <link rel="icon" type="image/x-icon" href="https://smg-adlersberg.de/neuesdesign/SMG-cropped-for-ico2_1.ico">
    <style>
        /* Performance-Optimierung: Preload wichtiger Ressourcen */
        .preload-hidden {
            display: none;
        }
        
        /* Verbessertes Error-Styling */
        .error-message {
            color: #d32f2f;
            background-color: #ffebee;
            border: 1px solid #e57373;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        
        .warning-message {
            color: #f57c00;
            background-color: #fff3e0;
            border: 1px solid #ffb74d;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        
        /* Loading-Indikator */
        .loading {
            display: none;
            margin: 10px 0;
            text-align: center;
        }
        
        .spinner {
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="https://smg-adlersberg.de/vertretungsplan/design/SMG-Logo2.png" alt="SMG Logo">
        <h1>Sophie-Mereau-Gymnasium</h1>
        <h2>Onlineplan</h2>
    </div>
    
    <div class="container">
        <h3>Vertretungsplan des SMG</h3>
        <p>Dieser Bereich enthält vertrauliche Daten.<br>Bitte loggen Sie sich mit Ihren persönlichen Anmeldedaten ein.</p>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?= $error ?>
            </div>
        <?php endif; ?>
        
        <?php if ($login_attempts >= 3 && $login_attempts < 5): ?>
            <div class="warning-message">
                Achtung: Sie haben bereits <?= $login_attempts ?> fehlgeschlagene Login-Versuche. 
                Bei 5 Versuchen wird der Login temporär gesperrt.
            </div>
        <?php endif; ?>
        
        <form method="POST" id="loginForm" <?= ($login_attempts >= 5) ? 'style="display:none;"' : '' ?>>
            <div class="form-group">
                <label for="name">Benutzername:</label>
                <input type="text" 
                       id="name" 
                       name="name" 
                       required 
                       maxlength="50"
                       autocomplete="username"
                       value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name'], ENT_QUOTES) : '' ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Passwort:</label>
                <input type="password" 
                       id="password" 
                       name="password" 
                       required 
                       maxlength="100"
                       autocomplete="current-password">
            </div>
            
            <button type="submit" class="btn" id="submitBtn">Einloggen</button>
            
            <div class="loading" id="loadingIndicator">
                <div class="spinner"></div>
                <span>Anmeldung wird verarbeitet...</span>
            </div>
        </form>
        
        <?php if ($login_attempts >= 5): ?>
            <div class="error-message">
                <strong>Account temporär gesperrt</strong><br>
                Zu viele fehlgeschlagene Login-Versuche. Bitte warten Sie einige Minuten oder wenden Sie sich an einen Administrator.
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Performance-Optimierung: Loading-Indikator und Form-Verbesserungen
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const loadingIndicator = document.getElementById('loadingIndicator');
            
            // Button deaktivieren und Loading anzeigen
            submitBtn.disabled = true;
            submitBtn.textContent = 'Wird verarbeitet...';
            loadingIndicator.style.display = 'block';
            
            // Timeout als Fallback (falls die Seite hängt)
            setTimeout(function() {
                if (submitBtn.disabled) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Einloggen';
                    loadingIndicator.style.display = 'none';
                }
            }, 10000); // 10 Sekunden Timeout
        });
        
        // Automatischer Focus auf Benutzername-Feld
        window.addEventListener('load', function() {
            document.getElementById('name').focus();
        });
        
        // Enter-Taste im Passwort-Feld
        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('loginForm').submit();
            }
        });
    </script>
</body>
</html>