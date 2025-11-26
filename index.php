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
        $table_escaped = escape_sql($this->connection, $table);
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

        $table_escaped = escape_sql($this->connection, $table);

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

    /* Получить размеры всех баз данных для диаграммы*/
    public function getAllDatabasesSizes()
    {
        $query = "SELECT 
                    TABLE_SCHEMA as database_name,
                    ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb
                  FROM information_schema.TABLES 
                  GROUP BY TABLE_SCHEMA 
                  ORDER BY size_mb DESC";

        $result = $this->connection->query($query);

        $sizes = [];
        while ($row = $result->fetch_assoc()) {
            $sizes[] = $row;
        }

        return $sizes;
    }

    /*Получить размеры таблиц в базе данных для диаграммы*/
    public function getTableSizes($database)
    {
        if (!$this->databaseExists($database)) {
            throw new Exception("База данных '$database' не найдена");
        }

        $query = "SELECT 
                    TABLE_NAME as table_name,
                    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb
                  FROM information_schema.TABLES 
                  WHERE table_schema = ?
                  ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC";

        $stmt = $this->connection->prepare($query);
        $stmt->bind_param('s', $database);
        $stmt->execute();
        $result = $stmt->get_result();

        $sizes = [];
        while ($row = $result->fetch_assoc()) {
            $sizes[] = $row;
        }

        return $sizes;
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

// Основная логика с простой обработкой ошибок
try {
    if (!empty($table_name) && !empty($db_name)) {
        // Страница таблицы
        $structure = $inspector->getTableStructure($db_name, $table_name);
        $tableData = $inspector->getTableData($db_name, $table_name, $page);
    } elseif (!empty($db_name)) {
        // Страница базы данных
        $tables = $inspector->getTables($db_name);
        $tableSizes = $inspector->getTableSizes($db_name);
    } else {
        // Главная страница - список баз данных
        $databases = $inspector->getDatabases();
        $dbSizes = $inspector->getAllDatabasesSizes();
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
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .table-container {
            overflow-x: auto;
        }

        .database-list .list-group-item:hover {
            transform: translateX(5px);
            transition: transform 0.2s;
        }

        .chart-scroll-container {
            overflow-x: auto;
            overflow-y: hidden;
            padding-bottom: 10px;
        }

        .chart-wrapper {
            min-width: 800px;
            height: 400px;
        }

        .legend-container {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 20px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
            padding: 5px;
            border-radius: 4px;
        }

        .legend-color {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            border-radius: 3px;
            border: 2px solid #fff;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container mt-4">
        <!-- Header -->
        <header class="card mb-4">
            <div class="card-body">
                <h1 class="card-title h3 text-primary">MySQL Web Inspector</h1>
                <nav class="breadcrumb">
                    <a class="breadcrumb-item" href="?">Базы данных</a>
                    <?php if (!empty($db_name) && !isset($error)): ?>
                        <a class="breadcrumb-item" href="?db_name=<?= escape($db_name) ?>"><?= escape($db_name) ?></a>
                    <?php endif; ?>
                    <?php if (!empty($table_name) && !isset($error)): ?>
                        <span class="breadcrumb-item active"><?= escape($table_name) ?></span>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <?php if (isset($error)): ?>
            <!-- Простой вывод ошибки -->
            <div class="alert alert-danger mb-4">
                <h4 class="alert-heading">Ошибка</h4>
                <?= escape($error) ?>
            </div>
        <?php else: ?>

            <main class="card">
                <div class="card-body">
                    <?php if (empty($db_name) && empty($table_name)): ?>
                        <!-- Главная страница - список баз данных -->
                        <section class="database-list">
                            <h2 class="h4 mb-3 text-secondary">Базы данных</h2>

                            <?php if (!empty($databases)): ?>
                                <div class="list-group mb-4">
                                    <?php foreach ($databases as $db): ?>
                                        <a href="?db_name=<?= escape($db) ?>" class="list-group-item list-group-item-action">
                                            <?= escape($db) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">Нет доступных баз данных</p>
                            <?php endif; ?>

                            <!-- Круговая диаграмма распределения по БД -->
                            <?php if (!empty($dbSizes)): ?>
                                <div class="card">
                                    <div class="card-body">
                                        <h5 class="card-title">Диаграмма распределения размеров по БД</h5>
                                        <div class="chart-scroll-container">
                                            <div class="chart-wrapper">
                                                <canvas id="databaseSizesChart"></canvas>
                                            </div>
                                        </div>
                                        <!-- Легенда под диаграммой -->
                                        <div id="databaseLegend" class="legend-container"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>

                    <?php elseif (!empty($db_name) && empty($table_name)): ?>
                        <!-- Страница базы данных - список таблиц -->
                        <section class="table-list">
                            <h2 class="h4 mb-3 text-secondary">База данных: <?= escape($db_name) ?></h2>

                            <?php if (!empty($tables)): ?>
                                <div class="list-group mb-4">
                                    <?php foreach ($tables as $table): ?>
                                        <a href="?db_name=<?= escape($db_name) ?>&table_name=<?= escape($table) ?>" class="list-group-item list-group-item-action">
                                            <?= escape($table) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">В этой базе данных нет таблиц</p>
                            <?php endif; ?>

                            <?php if (!empty($tableSizes)): ?>
                                <div class="card">
                                    <div class="card-body">
                                        <h5 class="card-title">Диаграмма распределения размера по таблицам</h5>
                                        <div class="chart-scroll-container">
                                            <div class="chart-wrapper">
                                                <canvas id="tableSizesChart"></canvas>
                                            </div>
                                        </div>

                                        <div id="tableLegend" class="legend-container"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>

                    <?php elseif (!empty($db_name) && !empty($table_name)): ?>
                        <!-- Страница таблицы -->
                        <section class="table-structure mb-5">
                            <h2 class="h4 mb-3 text-secondary">Таблица: <?= escape($table_name) ?></h2>

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
                                                <td><strong><?= escape($column['Field']) ?></strong></td>
                                                <td><code><?= escape($column['Type']) ?></code></td>
                                                <td><?= escape($column['Null']) ?></td>
                                                <td><?= escape($column['Key']) ?></td>
                                                <td><?= escape($column['Default'] ?? 'NULL') ?></td>
                                                <td><?= escape($column['Extra']) ?></td>
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

                                <a id="table-data"></a>

                                <div class="table-container border rounded mb-4">
                                    <table class="table table-striped table-bordered table-hover mb-0">
                                        <thead class="table-primary">
                                            <tr>
                                                <?php foreach (array_keys($tableData['data'][0]) as $column): ?>
                                                    <th><?= escape($column) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tableData['data'] as $row): ?>
                                                <tr>
                                                    <?php foreach ($row as $value): ?>
                                                        <td><?= escape($value ?? 'NULL') ?></td>
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
                                                    <a class="page-link" href="?db_name=<?= escape($db_name) ?>&table_name=<?= escape($table_name) ?>&page=<?= $tableData['currentPage'] - 1 ?>#table-data">
                                                        &laquo;
                                                    </a>
                                                </li>
                                            <?php endif; ?>

                                            <!-- Первая страница -->
                                            <li class="page-item <?= $tableData['currentPage'] == 1 ? 'active' : '' ?>">
                                                <a class="page-link" href="?db_name=<?= escape($db_name) ?>&table_name=<?= escape($table_name) ?>&page=1#table-data">
                                                    1
                                                </a>
                                            </li>

                                            <!-- Многоточие-->
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
                                                        <a class="page-link" href="?db_name=<?= escape($db_name) ?>&table_name=<?= escape($table_name) ?>&page=<?= $i ?>#table-data">
                                                            <?= $i ?>
                                                        </a>
                                                    </li>
                                            <?php endif;
                                            endfor; ?>

                                            <?php if ($tableData['currentPage'] < $tableData['totalPages'] - 2): ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">...</span>
                                                </li>
                                            <?php endif; ?>

                                            <?php if ($tableData['totalPages'] > 1): ?>
                                                <li class="page-item <?= $tableData['currentPage'] == $tableData['totalPages'] ? 'active' : '' ?>">
                                                    <a class="page-link" href="?db_name=<?= escape($db_name) ?>&table_name=<?= escape($table_name) ?>&page=<?= $tableData['totalPages'] ?>#table-data">
                                                        <?= $tableData['totalPages'] ?>
                                                    </a>
                                                </li>
                                            <?php endif; ?>

                                            <?php if ($tableData['currentPage'] < $tableData['totalPages']): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?db_name=<?= escape($db_name) ?>&table_name=<?= escape($table_name) ?>&page=<?= $tableData['currentPage'] + 1 ?>#table-data">
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Функция для генерации цветов
            function generateColors(count) {
                const baseColors = [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                    '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF',
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                    '#8A2BE2', '#5F9EA0', '#D2691E', '#6495ED',
                    '#DC143C', '#00CED1', '#9400D3', '#FF1493',
                    '#00BFFF', '#696969', '#1E90FF', '#B22222'
                ];

                const colors = [];
                for (let i = 0; i < count; i++) {
                    colors.push(baseColors[i % baseColors.length]);
                }
                return colors;
            }

            // Функция для создания кастомной легенды
            function createCustomLegend(chart, legendContainerId, data) {
                const legendContainer = document.getElementById(legendContainerId);
                if (!legendContainer) return;

                legendContainer.innerHTML = '';

                data.labels.forEach((label, index) => {
                    const value = data.datasets[0].data[index];
                    const color = data.datasets[0].backgroundColor[index];

                    const legendItem = document.createElement('div');
                    legendItem.className = 'legend-item';

                    legendItem.innerHTML = `
                    <div class="legend-color" style="background-color: ${color}"></div>
                    <div class="legend-text">
                        <strong>${label}</strong>: ${value} MB
                    </div>
                `;

                    legendContainer.appendChild(legendItem);
                });
            }

            // Диаграмма БД
            <?php if (!empty($dbSizes)): ?>
                const dbCtx = document.getElementById('databaseSizesChart');
                if (dbCtx) {
                    const dbLabels = <?= json_encode(array_column($dbSizes, 'database_name')) ?>;
                    const dbDataValues = <?= json_encode(array_column($dbSizes, 'size_mb')) ?>;
                    const dbColors = generateColors(dbLabels.length);

                    const dbData = {
                        labels: dbLabels,
                        datasets: [{
                            data: dbDataValues,
                            backgroundColor: dbColors,
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    };

                    new Chart(dbCtx, {
                        type: 'pie',
                        data: dbData,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                title: {
                                    display: true
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.raw || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = Math.round((value / total) * 100);
                                            return `${label}: ${value} MB`;
                                        }
                                    }
                                }
                            }
                        }
                    });

                    createCustomLegend(null, 'databaseLegend', dbData);
                }
            <?php endif; ?>

            // Диаграмма таблиц
            <?php if (!empty($tableSizes)): ?>
                const tableCtx = document.getElementById('tableSizesChart');
                if (tableCtx) {
                    const tableLabels = <?= json_encode(array_column($tableSizes, 'table_name')) ?>;
                    const tableDataValues = <?= json_encode(array_column($tableSizes, 'size_mb')) ?>;
                    const tableColors = generateColors(tableLabels.length);

                    const tableData = {
                        labels: tableLabels,
                        datasets: [{
                            data: tableDataValues,
                            backgroundColor: tableColors,
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    };

                    new Chart(tableCtx, {
                        type: 'pie',
                        data: tableData,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                title: {
                                    display: true
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.raw || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = Math.round((value / total) * 100);
                                            return `${label}: ${value} MB`;
                                        }
                                    }
                                }
                            }
                        }
                    });

                    createCustomLegend(null, 'tableLegend', tableData);
                }
            <?php endif; ?>
        });
    </script>

    <?php $inspector->close(); ?>
</body>

</html>