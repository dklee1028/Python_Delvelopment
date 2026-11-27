<?php
/* MRS 화면 진입점 — 설치 전이면 설치 화면으로 보냅니다. */
if (!is_file(__DIR__ . '/config.php')) { header('Location: install.php'); exit; }
header('Cache-Control: no-cache');
$v = @filemtime(__DIR__ . '/assets/app.js') ?: 1;
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>MRS · Management Report System</title>
<meta name="description" content="DATANEX 고객 보고서 자동 생성 시스템">
<meta name="theme-color" content="#1969A5">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icons/mrs-32.png" type="image/png">
<link rel="apple-touch-icon" href="assets/icons/mrs-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="MRS">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body>
<div id="app"></div>
<div id="toasts" aria-live="polite"></div>
<div id="modal-root"></div>
<canvas id="confetti" aria-hidden="true"></canvas>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
