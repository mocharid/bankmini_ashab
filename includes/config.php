<?php
// Deteksi protokol (http atau https)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';

// Deteksi nama host (misalnya, localhost, example.com, atau IP)
$host = $_SERVER['HTTP_HOST'];

// Deteksi path folder proyek secara dinamis
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$basePath = $scriptPath === '/' || $scriptPath === '\\' ? '' : $scriptPath;

// Gabungkan untuk membentuk BASE_URL
define('BASE_URL', $protocol . '://' . $host . $basePath . '/');
?>