<?php
require_once 'config.php';

class MySQLInspector
{
    private $connection;

    public function __construct()
    {
        $this->connect();
    }

    private function connect()
    {
        try {
            $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);

            if ($this->connection->connect_error) {
                throw new Exception("Ошибка подключения: " . $this->connection->connect_error);
            }

            $this->connection->set_charset(DB_CHARSET);
        } catch (Exception $e) {
            die("Ошибка подключения к MySQL: " . $e->getMessage());
        }
    }

    public function getDatabases()
    {
        $result = $this->connection->query("SHOW DATABASES");
        $databases = [];

        while ($row = $result->fetch_array()) {
            $databases[] = $row[0];
        }

        return $databases;
    }

    public function getTables($database)
    {
        $db_escaped = $this->connection->real_escape_string($database);
        $this->connection->select_db($database);

        $result = $this->connection->query("SHOW TABLES");
        $tables = [];

        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    public function getTableStructure($database, $table)
    {
        $this->connection->select_db($database);
        $tabble_escaped = $this->connection->real_escape_string($table);
        $result = $this->connection->query("DESCRIBE `$table`");

        if (!$result) {
            throw new Exception("Ошибка получения структуры таблицы: " . $this->connection->error);
        }

        $structure = [];

        while ($row = $result->fetch_assoc()) {
            $structure[] = $row;
        }

        return $structure;
    }

    public function getTableData($database, $table, $page = 1)
    {
        $this->connection->select_db($database);

        $offset = ($page - 1) * RECORDS_PER_PAGE;
        $limit = RECORDS_PER_PAGE;

        $tabble_escaped = $this->connection->real_escape_string($table);

        // Получаем общее количество записей
        $countResult = $this->connection->query("SELECT COUNT(*) as total FROM `$table`");
        if (!$countResult) {
            throw new Exception("Ошибка получения количества записей: " . $this->connection->error);
        }

        $totalRecords = $countResult->fetch_assoc()['total'];
        $totalPages = ceil($totalRecords / RECORDS_PER_PAGE);

        // Получаем данные с пагинацией
        $result = $this->connection->query("SELECT * FROM `$table` LIMIT $offset, $limit");
        
        if (!$result) {
            throw new Exception("Ошибка получения данных: " . $this->connection->error);
        }
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        return [
            'data' => $data,
            'totalRecords' => $totalRecords,
            'totalPages' => $totalPages,
            'currentPage' => $page
        ];
    }

    public function close()
    {
        if ($this->connection) {
            $this->connection->close();
        }
    }
}

// Обработка GET параметров
$db_name = $_GET['db_name'] ?? '';
$table_name = $_GET['table_name'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));

// Создаем экземпляр инспектора
$inspector = new MySQLInspector();

// Обработка ошибок
try {
    if (!empty($table_name) && !empty($db_name)) {
        // Страница таблицы
        $tables = $inspector->getTables($db_name);
        if (!in_array($table_name, $tables)) {
            throw new Exception("Таблица '$table_name' не найдена в базе данных '$db_name'");
        }

        $structure = $inspector->getTableStructure($db_name, $table_name);
        $tableData = $inspector->getTableData($db_name, $table_name, $page);
    } elseif (!empty($db_name)) {
        // Страница базы данных
        $databases = $inspector->getDatabases();
        if (!in_array($db_name, $databases)) {
            throw new Exception("База данных '$db_name' не найдена");
        }

        $tables = $inspector->getTables($db_name);
    } else {
        // Главная страница - список баз данных
        $databases = $inspector->getDatabases();
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MySQL Web Inspector</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>
    <div class="container">
        <header>
            <h1>MySQL Web Inspector</h1>
            <nav class="breadcrumb">
                <a href="?">Базы данных</a>
                <?php if (!empty($db_name)): ?>
                    &raquo; <a href="?db_name=<?= htmlspecialchars($db_name) ?>"><?= htmlspecialchars($db_name) ?></a>
                <?php endif; ?>
                <?php if (!empty($table_name)): ?>
                    &raquo; <span><?= htmlspecialchars($table_name) ?></span>
                <?php endif; ?>
            </nav>
        </header>

        <?php if (isset($error)): ?>
            <div class="error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <main>
            <?php if (empty($db_name) && empty($table_name)): ?>
                <!-- Главная страница - список баз данных -->
                <section class="database-list">
                    <h2>Базы данных</h2>
                    <?php if (!empty($databases)): ?>
                        <ul>
                            <?php foreach ($databases as $db): ?>
                                <li>
                                    <a href="?db_name=<?= htmlspecialchars($db) ?>">
                                        <?= htmlspecialchars($db) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>Нет доступных баз данных</p>
                    <?php endif; ?>
                </section>

            <?php elseif (!empty($db_name) && empty($table_name)): ?>
                <!-- Страница базы данных - список таблиц -->
                <section class="table-list">
                    <h2>База данных: <?= htmlspecialchars($db_name) ?></h2>
                    <p>Количество таблиц: <?= count($tables) ?></p>

                    <?php if (!empty($tables)): ?>
                        <ul>
                            <?php foreach ($tables as $table): ?>
                                <li>
                                    <a href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table) ?>">
                                        <?= htmlspecialchars($table) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>В этой базе данных нет таблиц</p>
                    <?php endif; ?>
                </section>

            <?php elseif (!empty($db_name) && !empty($table_name)): ?>
                <!-- Страница таблицы -->
                <section class="table-structure">
                    <h2>Таблица: <?= htmlspecialchars($table_name) ?></h2>

                    <h3>Структура таблицы</h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Поле</th>
                                    <th>Тип</th>
                                    <th>NULL</th>
                                    <th>Ключ</th>
                                    <th>По умолчанию</th>
                                    <th>Extra</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($structure as $column): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($column['Field']) ?></strong></td>
                                        <td><?= htmlspecialchars($column['Type']) ?></td>
                                        <td><?= htmlspecialchars($column['Null']) ?></td>
                                        <td><?= htmlspecialchars($column['Key']) ?></td>
                                        <td><?= htmlspecialchars($column['Default'] ?? 'NULL') ?></td>
                                        <td><?= htmlspecialchars($column['Extra']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="table-data">
                    <h3>Данные таблицы</h3>
                    <p>Всего записей: <?= $tableData['totalRecords'] ?></p>

                    <?php if (!empty($tableData['data'])): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <?php foreach (array_keys($tableData['data'][0]) as $column): ?>
                                            <th><?= htmlspecialchars($column) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tableData['data'] as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $value): ?>
                                                <td><?= htmlspecialchars($value ?? 'NULL') ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Пагинация -->
                        <?php if ($tableData['totalPages'] > 1): ?>
                            <div class="pagination">
                                <!-- Стрелка -->
                                <?php if ($tableData['currentPage'] > 1): ?>
                                    <a href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table_name) ?>&page=<?= $tableData['currentPage'] - 1 ?>" class="pagination-btn">
                                        &lsaquo;
                                    </a>
                                <?php endif; ?>

                                <!-- Первая -->
                                <a href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table_name) ?>&page=1"
                                    class="pagination-btn <?= $tableData['currentPage'] == 1 ? 'active' : '' ?>">
                                    1
                                </a>

                                <!-- Многоточие -->
                                <?php if ($tableData['currentPage'] > 3): ?>
                                    <span class="pagination-dots">...</span>
                                <?php endif; ?>

                                <!-- Страницы вокруг текущей -->
                                <?php
                                $startPage = max(2, $tableData['currentPage'] - 1);
                                $endPage = min($tableData['totalPages'] - 1, $tableData['currentPage'] + 1);

                                for ($i = $startPage; $i <= $endPage; $i++):
                                    if ($i > 1 && $i < $tableData['totalPages']): ?>
                                        <a href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table_name) ?>&page=<?= $i ?>"
                                            class="pagination-btn <?= $i == $tableData['currentPage'] ? 'active' : '' ?>">
                                            <?= $i ?>
                                        </a>
                                <?php endif;
                                endfor; ?>

                                <?php if ($tableData['currentPage'] < $tableData['totalPages'] - 2): ?>
                                    <span class="pagination-dots">...</span>
                                <?php endif; ?>

                                <!-- Последняя -->
                                <?php if ($tableData['totalPages'] > 1): ?>
                                    <a href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table_name) ?>&page=<?= $tableData['totalPages'] ?>"
                                        class="pagination-btn <?= $tableData['currentPage'] == $tableData['totalPages'] ? 'active' : '' ?>">
                                        <?= $tableData['totalPages'] ?>
                                    </a>
                                <?php endif; ?>

                                <!-- Стрелочка-->
                                <?php if ($tableData['currentPage'] < $tableData['totalPages']): ?>
                                    <a href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table_name) ?>&page=<?= $tableData['currentPage'] + 1 ?>" class="pagination-btn">
                                        &rsaquo;
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <p>Таблица не содержит данных</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <?php $inspector->close(); ?>
</body>

</html>