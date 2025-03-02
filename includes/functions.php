<?php
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function generateNoRekening() {
    return 'REK' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
}

function generateNoTransaksi() {
    return 'TRX' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
}
?>