<?php
// Конфигурация подключения к MySQL
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');

// Количество записей на странице
define('RECORDS_PER_PAGE', 50);

// Функция для безопасного вывода HTML
function escape($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Функция для безопасного экранирования SQL (добавьте эту)
function escape_sql($connection, $value) {
    return $connection->real_escape_string($value);
}
?>