<?php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// allow verify.php directly
if ($path === "/verify.php" || $path === "/index.php") {
  return false; // serve the real file
}

// route everything else (including "/") to index.php
require __DIR__ . "/index.php";
