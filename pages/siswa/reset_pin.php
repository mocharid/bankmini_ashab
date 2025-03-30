<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Check if token exists
if (!isset($_GET['token'])) {
    die('Token reset tidak valid');
}

$token = $_GET['token'];

// Verify token
$query = "SELECT id, reset_token_expiry FROM users WHERE reset_token = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    die('Token reset tidak valid atau sudah kadaluarsa');
}

// Check token expiry
if (strtotime($user['reset_token_expiry']) < time()) {
    die('Token reset sudah kadaluarsa');
}

// Store user ID in session for verification
$_SESSION['reset_pin_user_id'] = $user['id'];

// Generate CSRF token for form security
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Show reset PIN form
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Reset PIN - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-hover: #0a3a7d;
            --primary-light: rgba(12, 77, 162, 0.1);
            --text-color: #333;
            --text-light: #666;
            --border-color: #e1e5f0;
            --success-color: #00cc44;
            --warning-color: #ffaa00;
            --danger-color: #ff4d4d;
            --white: #fff;
            --shadow: 0 10px 30px rgba(12, 77, 162, 0.15);
            --transition: all 0.3s ease;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7ff 0%, #e9f1ff 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 15px;
            color: var(--text-color);
        }
        
        .reset-container {
            background: var(--white);
            padding: clamp(20px, 5vw, 35px);
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 500px;
            transition: var(--transition);
        }
        
        .reset-header {
            text-align: center;
            margin-bottom: clamp(20px, 5vw, 30px);
        }
        
        .reset-header h2 {
            color: var(--primary-color);
            margin-bottom: 10px;
            font-weight: 600;
            font-size: clamp(1.5rem, 4vw, 1.8rem);
        }
        
        .reset-header p {
            color: var(--text-light);
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        .form-group {
            margin-bottom: clamp(15px, 4vw, 25px);
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 500;
            color: var(--text-color);
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        .input-wrapper {
            position: relative;
            transition: var(--transition);
        }
        
        .input-wrapper i {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            color: var(--primary-color);
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        .input-wrapper input {
            width: 100%;
            padding: clamp(10px, 3vw, 14px) 15px clamp(10px, 3vw, 14px) 45px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: clamp(0.9rem, 3vw, 1rem);
            transition: var(--transition);
        }
        
        .input-wrapper input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px var(--primary-light);
            outline: none;
        }
        
        .btn {
            display: inline-block;
            padding: clamp(12px, 3vw, 14px) clamp(15px, 4vw, 25px);
            background: var(--primary-color);
            color: var(--white);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: clamp(0.9rem, 3vw, 1rem);
            width: 100%;
            font-weight: 500;
            transition: var(--transition);
            position: relative;
        }
        
        .btn:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(12, 77, 162, 0.2);
        }
        
        /* Animasi loading */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            visibility: hidden;
            opacity: 0;
            transition: var(--transition);
        }
        
        .loading-overlay.active {
            visibility: visible;
            opacity: 1;
        }
        
        .spinner {
            width: clamp(40px, 10vw, 50px);
            height: clamp(40px, 10vw, 50px);
            border: 5px solid var(--primary-light);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* PIN strength indicator */
        .pin-strength {
            height: 5px;
            background: var(--border-color);
            margin-top: 8px;
            border-radius: 5px;
            overflow: hidden;
            position: relative;
        }
        
        .pin-strength-bar {
            height: 100%;
            width: 0;
            transition: var(--transition);
        }
        
        .pin-message {
            font-size: clamp(0.7rem, 2.5vw, 0.8rem);
            margin-top: 5px;
            color: var(--text-light);
        }
        
        /* Success animation */
        .success-checkmark {
            display: none;
            text-align: center;
            margin: clamp(15px, 4vw, 20px) 0;
        }
        
        .success-checkmark i {
            color: var(--success-color);
            font-size: clamp(40px, 12vw, 60px);
            animation: scaleCheck 0.5s ease;
        }
        
        .success-checkmark p {
            margin-top: 10px;
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        @keyframes scaleCheck {
            0% { transform: scale(0); }
            60% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        /* Logo */
        .bank-logo {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .bank-logo img {
            max-width: clamp(120px, 35vw, 180px);
            height: auto;
        }
        
        /* Error message */
        .error-message {
            background-color: #ffeded;
            border-left: 4px solid var(--danger-color);
            color: #d32f2f;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 0.9rem;
            display: none;
        }
        
        /* Responsive adjustments */
        @media screen and (max-width: 480px) {
            .reset-container {
                border-radius: 15px;
            }
            
            .btn {
                padding-left: 15px;
                padding-right: 15px;
            }
        }
        
        @media screen and (max-width: 360px) {
            body {
                padding: 10px;
            }
            
            .reset-container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="bank-logo">
            <!-- Placeholder for bank logo -->
            <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxODAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCAxODAgNjAiPjxyZWN0IHdpZHRoPSIxODAiIGhlaWdodD0iNjAiIGZpbGw9IiMwYzRkYTIiIHJ4PSI4Ii8+PHRleHQgeD0iOTAiIHk9IjM2IiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMjAiIGZpbGw9IndoaXRlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmb250LXdlaWdodD0iYm9sZCI+U0NIT0JBTks8L3RleHQ+PC9zdmc+" alt="SCHOBANK Logo">
        </div>
        
        <div class="reset-header">
            <h2><i class="fas fa-key"></i> Reset PIN</h2>
            <p>Silakan buat PIN baru untuk akun Anda</p>
        </div>
        
        <div id="errorMessage" class="error-message">
            <i class="fas fa-exclamation-circle"></i> <span id="errorText"></span>
        </div>
        
        <div class="success-checkmark">
            <i class="fas fa-check-circle"></i>
            <p>PIN berhasil diperbarui!</p>
        </div>
        
        <form id="resetPinForm" method="POST" action="process_reset_pin.php">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($user['id']) ?>">
            
            <div class="form-group">
                <label for="new_pin">PIN Baru (6 digit angka)</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="new_pin" name="new_pin" required placeholder="Masukkan PIN baru" maxlength="6" pattern="[0-9]{6}" inputmode="numeric">
                </div>
                <div class="pin-strength">
                    <div class="pin-strength-bar"></div>
                </div>
                <div class="pin-message"></div>
            </div>
            
            <div class="form-group">
                <label for="confirm_pin">Konfirmasi PIN Baru</label>
                <div class="input-wrapper">
                    <i class="fas fa-check-circle"></i>
                    <input type="password" id="confirm_pin" name="confirm_pin" required placeholder="Konfirmasi PIN baru" maxlength="6" pattern="[0-9]{6}" inputmode="numeric">
                </div>
            </div>
            
            <button type="submit" class="btn" id="submitBtn">
                <i class="fas fa-save"></i> Simpan PIN Baru
            </button>
        </form>
    </div>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('resetPinForm');
            const loadingOverlay = document.getElementById('loadingOverlay');
            const newPinInput = document.getElementById('new_pin');
            const confirmPinInput = document.getElementById('confirm_pin');
            const pinStrengthBar = document.querySelector('.pin-strength-bar');
            const pinMessage = document.querySelector('.pin-message');
            const resetContainer = document.querySelector('.reset-container');
            const successCheckmark = document.querySelector('.success-checkmark');
            const errorMessage = document.getElementById('errorMessage');
            const errorText = document.getElementById('errorText');
            
            // Check for error parameter in URL
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('error')) {
                errorText.textContent = decodeURIComponent(urlParams.get('error'));
                errorMessage.style.display = 'block';
            }
            
            // Focus input when page loads
            setTimeout(function() {
                newPinInput.focus();
            }, 500);
            
            // Check PIN strength
            newPinInput.addEventListener('input', function() {
                const pin = this.value;
                let strength = 0;
                let message = '';
                
                // Allow only numbers
                this.value = this.value.replace(/[^0-9]/g, '');
                
                if (pin.length === 0) {
                    strength = 0;
                    message = '';
                } else if (pin.length < 6) {
                    strength = 25;
                    message = 'Terlalu pendek';
                    pinStrengthBar.style.backgroundColor = '#ff4d4d';
                } else {
                    // Check for repeated digits
                    const hasRepeatedDigits = /(\d)\1{2,}/.test(pin);
                    
                    // Check for sequential digits
                    const isSequential = 
                        /012|123|234|345|456|567|678|789/.test(pin) || 
                        /987|876|765|654|543|432|321|210/.test(pin);
                    
                    if (hasRepeatedDigits && isSequential) {
                        strength = 25;
                        message = 'PIN terlalu lemah';
                        pinStrengthBar.style.backgroundColor = '#ff4d4d';
                    } else if (hasRepeatedDigits || isSequential) {
                        strength = 50;
                        message = 'PIN cukup lemah';
                        pinStrengthBar.style.backgroundColor = '#ffaa00';
                    } else {
                        strength = 100;
                        message = 'PIN kuat';
                        pinStrengthBar.style.backgroundColor = '#00cc44';
                    }
                }
                
                pinStrengthBar.style.width = strength + '%';
                pinMessage.textContent = message;
                
                // Also check confirm PIN if it has a value
                if (confirmPinInput.value.length > 0) {
                    checkPinMatch();
                }
            });
            
            // Allow only numeric input
            const numericInputs = document.querySelectorAll('input[type="password"]');
            numericInputs.forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (!/[0-9]/.test(e.key)) {
                        e.preventDefault();
                    }
                });
            });
            
            // Check if PINs match
            confirmPinInput.addEventListener('input', function() {
                // Allow only numbers
                this.value = this.value.replace(/[^0-9]/g, '');
                checkPinMatch();
            });
            
            function checkPinMatch() {
                if (confirmPinInput.value !== newPinInput.value) {
                    confirmPinInput.style.borderColor = '#ff4d4d';
                    confirmPinInput.style.boxShadow = '0 0 0 3px rgba(255, 77, 77, 0.1)';
                } else {
                    confirmPinInput.style.borderColor = '#00cc44';
                    confirmPinInput.style.boxShadow = '0 0 0 3px rgba(0, 204, 68, 0.1)';
                }
            }
            
            // Form submission
            form.addEventListener('submit', function(event) {
                // Validate inputs
                if (newPinInput.value.length !== 6 || !/^\d{6}$/.test(newPinInput.value)) {
                    event.preventDefault();
                    showToast('PIN harus terdiri dari 6 digit angka');
                    newPinInput.focus();
                    return false;
                }
                
                if (newPinInput.value !== confirmPinInput.value) {
                    event.preventDefault();
                    showToast('PIN konfirmasi tidak cocok');
                    confirmPinInput.focus();
                    return false;
                }
                
                // Show loading overlay
                loadingOverlay.classList.add('active');
                
                // Continue with form submission
                return true;
            });
            
            // Toast notification function
            function showToast(message) {
                // Check if toast container exists, if not create it
                let toastContainer = document.getElementById('toastContainer');
                if (!toastContainer) {
                    toastContainer = document.createElement('div');
                    toastContainer.id = 'toastContainer';
                    toastContainer.style.position = 'fixed';
                    toastContainer.style.bottom = '20px';
                    toastContainer.style.left = '50%';
                    toastContainer.style.transform = 'translateX(-50%)';
                    toastContainer.style.zIndex = '2000';
                    document.body.appendChild(toastContainer);
                }
                
                // Create toast
                const toast = document.createElement('div');
                toast.style.backgroundColor = '#333';
                toast.style.color = '#fff';
                toast.style.padding = '10px 20px';
                toast.style.borderRadius = '5px';
                toast.style.marginBottom = '10px';
                toast.style.boxShadow = '0 2px 10px rgba(0,0,0,0.2)';
                toast.style.fontSize = 'clamp(0.8rem, 3vw, 0.9rem)';
                toast.style.textAlign = 'center';
                toast.style.minWidth = '200px';
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s ease';
                
                // Add icon and message
                toast.innerHTML = `<i class="fas fa-exclamation-circle" style="margin-right: 8px;"></i>${message}`;
                
                // Add to container
                toastContainer.appendChild(toast);
                
                // Show toast
                setTimeout(() => {
                    toast.style.opacity = '1';
                }, 10);
                
                // Remove after 3 seconds
                setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => {
                        toastContainer.removeChild(toast);
                    }, 300);
                }, 3000);
            }
        });
    </script>
</body>
</html>