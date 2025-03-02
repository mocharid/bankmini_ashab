<?php
require_once 'functions.php'; // Tambahkan baris ini
session_start();

if (!isLoggedIn()) {
    redirect('pages/login.php');
}
?>