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

    public function databaseExists($database)
    {
        $databases = $this->getDatabases();
        return in_array($database, $databases);
    }

    public function getTables($database)
    {
        if (!$this->databaseExists($database)) {
            throw new Exception("База данных '$database' не найдена");
        }

        $this->connection->select_db($database);
        $result = $this->connection->query("SHOW TABLES");

        if (!$result) {
            throw new Exception("Ошибка при получении списка таблиц");
        }

        $tables = [];
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    public function tableExists($database, $table)
    {
        try {
            $tables = $this->getTables($database);
            return in_array($table, $tables);
        } catch (Exception $e) {
            return false;
        }
    }

    public function getTableStructure($database, $table)
    {
        if (!$this->tableExists($database, $table)) {
            throw new Exception("Таблица '$table' не найдена в базе данных '$database'");
        }

        $this->connection->select_db($database);
        $table_escaped = $this->connection->real_escape_string($table);
        $result = $this->connection->query("DESCRIBE `$table_escaped`");

        if (!$result) {
            throw new Exception("Ошибка получения структуры таблицы");
        }

        $structure = [];
        while ($row = $result->fetch_assoc()) {
            $structure[] = $row;
        }

        return $structure;
    }

    public function getTableData($database, $table, $page = 1)
    {
        if (!$this->tableExists($database, $table)) {
            throw new Exception("Таблица '$table' не найдена в базе данных '$database'");
        }

        $this->connection->select_db($database);

        $offset = ($page - 1) * RECORDS_PER_PAGE;
        $limit = RECORDS_PER_PAGE;

        $table_escaped = $this->connection->real_escape_string($table);

        // количество записей
        $countResult = $this->connection->query("SELECT COUNT(*) as total FROM `$table_escaped`");
        if (!$countResult) {
            throw new Exception("Ошибка получения количества записей");
        }

        $totalRecords = $countResult->fetch_assoc()['total'];
        $totalPages = ceil($totalRecords / RECORDS_PER_PAGE);

        // Данные с пагинацией
        $result = $this->connection->query("SELECT * FROM `$table_escaped` LIMIT $offset, $limit");

        if (!$result) {
            throw new Exception("Ошибка получения данных");
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

// экземпляр инспектора
$inspector = new MySQLInspector();

// Основная логика с простой обработкой ошибок
try {
    if (!empty($table_name) && !empty($db_name)) {
        $structure = $inspector->getTableStructure($db_name, $table_name);
        $tableData = $inspector->getTableData($db_name, $table_name, $page);
    } elseif (!empty($db_name)) {
        $tables = $inspector->getTables($db_name);
    } else {
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-container {
            overflow-x: auto;
        }

        .database-list .list-group-item:hover {
            transform: translateX(5px);
            transition: transform 0.2s;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container mt-4">
        <header class="card mb-4">
            <div class="card-body">
                <h1 class="card-title h3 text-primary">MySQL Web Inspector</h1>
                <nav class="breadcrumb">
                    <a class="breadcrumb-item" href="?">Базы данных</a>
                    <?php if (!empty($db_name) && !isset($error)): ?>
                        <a class="breadcrumb-item" href="?db_name=<?= htmlspecialchars($db_name) ?>"><?= htmlspecialchars($db_name) ?></a>
                    <?php endif; ?>
                    <?php if (!empty($table_name) && !isset($error)): ?>
                        <span class="breadcrumb-item active"><?= htmlspecialchars($table_name) ?></span>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <?php if (isset($error)): ?>
            <!--вывод ошибки -->
            <div class="alert alert-danger mb-4">
                <h4 class="alert-heading">Ошибка</h4>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php else: ?>

            <main class="card">
                <div class="card-body">
                    <?php if (empty($db_name) && empty($table_name)): ?>
                        <!-- список баз данных -->
                        <section class="database-list">
                            <h2 class="h4 mb-3 text-secondary">Базы данных</h2>
                            <?php if (!empty($databases)): ?>
                                <div class="list-group">
                                    <?php foreach ($databases as $db): ?>
                                        <a href="?db_name=<?= htmlspecialchars($db) ?>" class="list-group-item list-group-item-action">
                                            <?= htmlspecialchars($db) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">Нет доступных баз данных</p>
                            <?php endif; ?>
                        </section>

                    <?php elseif (!empty($db_name) && empty($table_name)): ?>
                       
                        <!-- список таблиц -->
                        <section class="table-list">
                            <h2 class="h4 mb-3 text-secondary">База данных: <?= htmlspecialchars($db_name) ?></h2>
                            <p class="text-muted">Количество таблиц: <?= count($tables) ?></p>

                            <?php if (!empty($tables)): ?>
                                <div class="list-group">
                                    <?php foreach ($tables as $table): ?>
                                        <a href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table) ?>" class="list-group-item list-group-item-action">
                                            <?= htmlspecialchars($table) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">В этой базе данных нет таблиц</p>
                            <?php endif; ?>
                        </section>

                    <?php elseif (!empty($db_name) && !empty($table_name)): ?>

                        <section class="table-structure mb-5">
                            <h2 class="h4 mb-3 text-secondary">Таблица: <?= htmlspecialchars($table_name) ?></h2>

                            <h3 class="h5 mb-3">Структура таблицы</h3>
                            <div class="table-container border rounded">
                                <table class="table table-striped table-bordered mb-0">
                                    <thead class="table-dark">
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
                                                <td><code><?= htmlspecialchars($column['Type']) ?></code></td>
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
                            <h3 class="h5 mb-3">Данные таблицы</h3>
                            <p class="text-muted">Всего записей: <?= $tableData['totalRecords'] ?></p>

                            <?php if (!empty($tableData['data'])): ?>
                                <!-- Якорь -->
                                <a id="table-data"></a>

                                <div class="table-container border rounded mb-4">
                                    <table class="table table-striped table-bordered table-hover mb-0">
                                        <thead class="table-primary">
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
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination justify-content-center">
                                            <!-- Стрелка назад -->
                                            <?php if ($tableData['currentPage'] > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table_name) ?>&page=<?= $tableData['currentPage'] - 1 ?>#table-data">
                                                        &laquo;
                                                    </a>
                                                </li>
                                            <?php endif; ?>

                                            <li class="page-item <?= $tableData['currentPage'] == 1 ? 'active' : '' ?>">
                                                <a class="page-link" href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table_name) ?>&page=1#table-data">
                                                    1
                                                </a>
                                            </li>

                                            <!-- Многоточие если нужно -->
                                            <?php if ($tableData['currentPage'] > 3): ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">...</span>
                                                </li>
                                            <?php endif; ?>

                                            <!-- Страницы вокруг текущей -->
                                            <?php
                                            $startPage = max(2, $tableData['currentPage'] - 1);
                                            $endPage = min($tableData['totalPages'] - 1, $tableData['currentPage'] + 1);

                                            for ($i = $startPage; $i <= $endPage; $i++):
                                                if ($i > 1 && $i < $tableData['totalPages']): ?>
                                                    <li class="page-item <?= $i == $tableData['currentPage'] ? 'active' : '' ?>">
                                                        <a class="page-link" href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table_name) ?>&page=<?= $i ?>#table-data">
                                                            <?= $i ?>
                                                        </a>
                                                    </li>
                                            <?php endif;
                                            endfor; ?>

                                            <!-- Многоточие если нужно -->
                                            <?php if ($tableData['currentPage'] < $tableData['totalPages'] - 2): ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">...</span>
                                                </li>
                                            <?php endif; ?>

                                            <!-- Последняя страница -->
                                            <?php if ($tableData['totalPages'] > 1): ?>
                                                <li class="page-item <?= $tableData['currentPage'] == $tableData['totalPages'] ? 'active' : '' ?>">
                                                    <a class="page-link" href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table_name) ?>&page=<?= $tableData['totalPages'] ?>#table-data">
                                                        <?= $tableData['totalPages'] ?>
                                                    </a>
                                                </li>
                                            <?php endif; ?>

                                            <!-- Стрелка вперед -->
                                            <?php if ($tableData['currentPage'] < $tableData['totalPages']): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?db_name=<?= htmlspecialchars($db_name) ?>&table_name=<?= htmlspecialchars($table_name) ?>&page=<?= $tableData['currentPage'] + 1 ?>#table-data">
                                                        &raquo;
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>

                            <?php else: ?>
                                <div class="alert alert-info">
                                    Таблица не содержит данных
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </div>
            </main>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <?php $inspector->close(); ?>
</body>

</html>