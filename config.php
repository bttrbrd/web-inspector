<?php
// Конфигурация подключения
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');

define('RECORDS_PER_PAGE', 50);

// Функция для безопасного вывода HTML
function escape($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

//Защита от SQL инъекций
function escape_sql($connection, $value) {
    return $connection->real_escape_string($value);
}
?>