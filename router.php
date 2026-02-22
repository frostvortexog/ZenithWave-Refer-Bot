<?php
// Route ALL requests (including "/") to index.php,
// but still allow direct access to verify.php and index.php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

if ($path === "/verify.php" || $path === "/index.php") {
  return false; // serve the real file directly
}

require __DIR__ . "/index.php";
