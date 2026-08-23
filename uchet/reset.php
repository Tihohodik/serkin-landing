<?php
// Аварийный сброс пароля любого пользователя (в т.ч. администратора).
// Запуск ТОЛЬКО из консоли по SSH:  php reset.php <логин>
// В веб-браузере скрипт не работает.
if (php_sapi_name() !== 'cli') { http_response_code(403); die('Forbidden'); }

$AUTH = __DIR__ . '/auth.php';
$GUARD = "<?php http_response_code(403); die('Forbidden'); ?>\n";
$login = $argv[1] ?? '';

if ($login === '') { fwrite(STDERR, "Использование: php reset.php <логин>\n"); exit(1); }
if (!file_exists($AUTH)) { fwrite(STDERR, "Файл auth.php не найден — учётные записи ещё не созданы.\n"); exit(1); }

$c = (string)file_get_contents($AUTH);
$p = strpos($c, "\n"); if ($p !== false) $c = substr($c, $p + 1);
$data = json_decode($c, true);
if (!is_array($data) || empty($data['users'])) { fwrite(STDERR, "Не удалось прочитать список пользователей.\n"); exit(1); }

$found = false; $token = '';
foreach ($data['users'] as &$u) {
  if (isset($u['login']) && mb_strtolower($u['login']) === mb_strtolower($login)) {
    $token = bin2hex(random_bytes(8));
    $u['hash'] = null; $u['invite'] = $token; $u['active'] = true;
    $found = true; break;
  }
}
unset($u);

if (!$found) {
  fwrite(STDERR, "Пользователь «$login» не найден. Доступные логины:\n");
  foreach ($data['users'] as $u) fwrite(STDERR, "  - " . ($u['login'] ?? '?') . "\n");
  exit(1);
}

$ok = @file_put_contents($AUTH, $GUARD . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
if ($ok === false) { fwrite(STDERR, "Ошибка записи в auth.php (проверьте права).\n"); exit(1); }

echo "Готово. Пароль для «$login» сброшен.\n";
echo "Откройте эту ссылку и задайте новый пароль:\n";
echo "https://serkinauto.ru/uchet/?invite=$token\n";
