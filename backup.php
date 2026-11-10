<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

require_admin();

$message = '';

/**
 * Prefix text values that spreadsheet applications might interpret
 * as formulas. Numeric database columns remain unchanged.
 */
$makeCsvCellSafe = function ($value, $isNumericColumn) {
    if ($value === null) {
        return '';
    }

    $value = (string) $value;

    if (
        !$isNumericColumn
        && preg_match('/^[\x00-\x20]*[=+\-@]/', $value)
    ) {
        $value = "'" . $value;
    }

    return $value;
};

/**
 * Write one database table to an open CSV file handle.
 */
$writeCsvTable = function ($table, $output) use ($pdo, $makeCsvCellSafe) {
    $quotedTable = '`' . str_replace('`', '``', (string) $table) . '`';

    $columnInfo = $pdo
        ->query("SHOW COLUMNS FROM $quotedTable")
        ->fetchAll(PDO::FETCH_ASSOC);

    $headers = array();
    $numericColumns = array();

    foreach ($columnInfo as $column) {
        $columnName = isset($column['Field']) ? $column['Field'] : '';
        $columnType = isset($column['Type']) ? $column['Type'] : '';

        $headers[] = $makeCsvCellSafe($columnName, false);

        $numericColumns[] = preg_match(
            '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|year)\b/i',
            $columnType
        ) === 1;
    }

    if (fputcsv($output, $headers, ',', '"', '\\') === false) {
        throw new RuntimeException('Could not write CSV headers.');
    }

    $rows = $pdo->query("SELECT * FROM $quotedTable");

    while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
        $csvRow = array();

        foreach ($row as $columnIndex => $value) {
            $isNumericColumn = isset($numericColumns[$columnIndex])
                ? $numericColumns[$columnIndex]
                : false;

            $csvRow[] = $makeCsvCellSafe($value, $isNumericColumn);
        }

        if (fputcsv($output, $csvRow, ',', '"', '\\') === false) {
            throw new RuntimeException('Could not write CSV data.');
        }
    }
};

// --- Download all tables as CSV files in one ZIP ---
if (isset($_GET['csv_all'])) {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit(
            'The server does not have the PHP ZipArchive extension enabled. '
            . 'Use the SQL backup option or ask the hosting provider to enable it.'
        );
    }

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    $zipPath = tempnam(sys_get_temp_dir(), 'vscms_zip_');

    if ($zipPath === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Could not create a temporary ZIP file on the server.');
    }

    $zip = new ZipArchive();
    $openResult = $zip->open(
        $zipPath,
        ZipArchive::CREATE | ZipArchive::OVERWRITE
    );

    if ($openResult !== true) {
        @unlink($zipPath);
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Could not open a ZIP file for the download.');
    }

    $zipIsOpen = true;
    $csvPaths = array();

    try {
        if (!$tables) {
            if (!$zip->addFromString('README.txt', 'No database tables were found.')) {
                throw new RuntimeException('Could not add the ZIP readme.');
            }
        } else {
            foreach ($tables as $tableIndex => $table) {
                $csvPath = tempnam(sys_get_temp_dir(), 'vscms_csv_');

                if ($csvPath === false) {
                    throw new RuntimeException('Could not create a temporary CSV file.');
                }

                $csvPaths[] = $csvPath;
                $output = @fopen($csvPath, 'wb');

                if ($output === false) {
                    throw new RuntimeException('Could not open a temporary CSV file.');
                }

                try {
                    // UTF-8 BOM helps Excel recognize the CSV encoding.
                    if (fwrite($output, "\xEF\xBB\xBF") === false) {
                        throw new RuntimeException('Could not write the CSV file.');
                    }

                    $writeCsvTable($table, $output);
                } finally {
                    fclose($output);
                }

                $safeTableName = preg_replace(
                    '/[^A-Za-z0-9_-]/',
                    '_',
                    (string) $table
                );

                if ($safeTableName === '') {
                    $safeTableName = 'table';
                }

                // The number prefix keeps filenames unique.
                $archiveName = sprintf(
                    '%03d_%s.csv',
                    $tableIndex + 1,
                    $safeTableName
                );

                if (!$zip->addFile($csvPath, $archiveName)) {
                    throw new RuntimeException('Could not add a CSV file to the ZIP.');
                }
            }
        }

        if (!$zip->close()) {
            $zipIsOpen = false;
            throw new RuntimeException('Could not finish creating the ZIP file.');
        }

        $zipIsOpen = false;
    } catch (Throwable $ex) {
        if ($zipIsOpen) {
            try {
                $zip->close();
            } catch (Throwable $ignored) {
                // Ignore errors while closing a failed archive.
            }
        }

        foreach ($csvPaths as $csvPath) {
            @unlink($csvPath);
        }

        @unlink($zipPath);
        error_log('VSCMS CSV ZIP export failed: ' . $ex->getMessage());

        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit(
            'Could not create the ZIP download. Check that the server has '
            . 'ZipArchive enabled and that its temporary directory is writable.'
        );
    }

    // ZipArchive needs the CSV files to remain available until close() completes.
    foreach ($csvPaths as $csvPath) {
        @unlink($csvPath);
    }

    if (!is_file($zipPath) || filesize($zipPath) === false) {
        @unlink($zipPath);
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('The ZIP download could not be prepared.');
    }

    $filename = 'vscms_all_tables_' . date('Y-m-d_His') . '.zip';

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// --- Download one table as CSV ---
if (isset($_GET['csv'])) {
    $table = isset($_GET['table']) && !is_array($_GET['table'])
        ? (string) $_GET['table']
        : '';

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    if ($table === '' || !in_array($table, $tables, true)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Invalid table selection.');
    }

    $safeTableName = preg_replace('/[^A-Za-z0-9_-]/', '_', $table);
    $filename = 'vscms_' . $safeTableName . '_' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    $output = fopen('php://output', 'w');

    if ($output === false) {
        http_response_code(500);
        exit('Could not create the CSV download.');
    }

    // UTF-8 BOM helps Excel recognize the CSV encoding.
    fwrite($output, "\xEF\xBB\xBF");

    try {
        $writeCsvTable($table, $output);
    } catch (Throwable $ex) {
        error_log('VSCMS CSV export failed: ' . $ex->getMessage());
    }

    fclose($output);
    exit;
}

// --- Download all tables as an SQL backup ---
if (isset($_GET['download'])) {
    header('Content-Type: application/sql; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="vscms_backup_'
        . date('Y-m-d_His')
        . '.sql"'
    );
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    echo "-- VSCMS Database Backup - " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($tables as $table) {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';

        echo "DROP TABLE IF EXISTS $quotedTable;\n";

        $create = $pdo
            ->query("SHOW CREATE TABLE $quotedTable")
            ->fetch(PDO::FETCH_ASSOC);

        if ($create && isset($create['Create Table'])) {
            echo $create['Create Table'] . ";\n\n";
        }

        $rows = $pdo->query("SELECT * FROM $quotedTable");

        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            $values = array();

            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : $pdo->quote($value);
            }

            echo "INSERT INTO $quotedTable VALUES ("
                . implode(',', $values)
                . ");\n";
        }

        echo "\n";
    }

    exit;
}

// --- Restore from an SQL backup ---
if (
    isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_FILES['sqlfile'])
) {
    $file = isset($_FILES['sqlfile']['tmp_name'])
        ? $_FILES['sqlfile']['tmp_name']
        : '';

    $uploadError = isset($_FILES['sqlfile']['error'])
        ? $_FILES['sqlfile']['error']
        : UPLOAD_ERR_NO_FILE;

    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = '<div class="mb-4 px-4 py-3 rounded-lg text-sm bg-red-100 text-red-800">'
            . 'The file upload failed. Check the server upload size limit and try again.'
            . '</div>';
    } elseif (!$file || !is_uploaded_file($file)) {
        $message = '<div class="mb-4 px-4 py-3 rounded-lg text-sm bg-red-100 text-red-800">'
            . 'Invalid uploaded file. Restore aborted.'
            . '</div>';
    } else {
        $sql = file_get_contents($file);

        if (
            $sql === false
            || stripos($sql, 'DROP TABLE') === false
            || stripos($sql, 'CREATE TABLE') === false
        ) {
            $message = '<div class="mb-4 px-4 py-3 rounded-lg text-sm bg-red-100 text-red-800">'
                . 'Invalid backup file. Restore aborted for safety.'
                . '</div>';
        } else {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                $pdo->exec($sql);
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

                flash('Database restored successfully from backup.');
                redirect('backup.php');
            } catch (Throwable $ex) {
                try {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                } catch (Throwable $ignored) {
                    // Ignore errors while resetting foreign-key checks.
                }

                $message = '<div class="mb-4 px-4 py-3 rounded-lg text-sm bg-red-100 text-red-800">'
                    . 'Restore failed: ' . e($ex->getMessage())
                    . '</div>';
            }
        }
    }
}

$tablesForCsv = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

page_header('Backup & Restore');
?>

<?= $message ?>

<div class="grid md:grid-cols-2 gap-6 max-w-4xl">

    <!-- Download backups -->
    <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="font-bold text-gray-700 mb-2">
            <i class="fa-solid fa-download text-blue-600 mr-2"></i>
            Download Backup
        </h2>

        <p class="text-sm text-gray-500 mb-4">
            Download a complete SQL backup or export table data as CSV files.
        </p>

        <a
            href="backup.php?download=1"
            class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg"
        >
            <i class="fa-solid fa-database mr-1"></i>
            Download SQL Backup (.sql)
        </a>

        <div class="border-t mt-5 pt-5">
            <h3 class="font-semibold text-gray-700 mb-2">
                Download all tables as CSV
            </h3>

            <p class="text-sm text-gray-500 mb-3">
                Downloads one ZIP file containing a separate CSV file for each table.
                The CSV files can be opened in Excel.
            </p>

            <?php if (class_exists('ZipArchive')): ?>
                <a
                    href="backup.php?csv_all=1"
                    class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg"
                >
                    <i class="fa-solid fa-file-zipper mr-1"></i>
                    Download All Tables (.zip)
                </a>
            <?php else: ?>
                <div class="text-sm text-amber-700 bg-amber-50 rounded-lg p-3">
                    The server's PHP ZipArchive extension is unavailable, so the
                    all-tables CSV ZIP download cannot be created. The SQL backup
                    above still includes all tables.
                </div>
            <?php endif; ?>
        </div>

        <div class="border-t mt-5 pt-5">
            <h3 class="font-semibold text-gray-700 mb-2">
                Download one table as CSV
            </h3>

            <?php if ($tablesForCsv): ?>
                <form method="get" action="backup.php" class="space-y-3">
                    <input type="hidden" name="csv" value="1">

                    <label
                        for="csv_table"
                        class="block text-sm font-medium text-gray-700"
                    >
                        Select a table
                    </label>

                    <select
                        id="csv_table"
                        name="table"
                        required
                        class="w-full text-sm border rounded-lg px-3 py-2 bg-white"
                    >
                        <option value="">Choose a table</option>

                        <?php foreach ($tablesForCsv as $tableName): ?>
                            <option value="<?= e($tableName) ?>">
                                <?= e($tableName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button
                        type="submit"
                        class="bg-gray-700 hover:bg-gray-800 text-white text-sm font-medium px-5 py-2.5 rounded-lg"
                    >
                        <i class="fa-solid fa-file-csv mr-1"></i>
                        Download Selected Table
                    </button>
                </form>
            <?php else: ?>
                <p class="text-sm text-gray-500">
                    No database tables were found.
                </p>
            <?php endif; ?>
        </div>

        <div class="mt-5 text-xs text-gray-400 bg-gray-50 rounded-lg p-3">
            <strong>SQL recovery guidance:</strong>
            You can restore an SQL backup using the form on the right, or import
            it via phpMyAdmin or the command line:

            <br>

            <code class="text-gray-600">
                mysql -u root -p vscms &lt; backup.sql
            </code>
        </div>
    </div>

    <!-- Restore backup -->
    <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="font-bold text-gray-700 mb-2">
            <i class="fa-solid fa-upload text-emerald-600 mr-2"></i>
            Restore from Backup
        </h2>

        <p class="text-sm text-gray-500 mb-4">
            Upload a previously downloaded <code>.sql</code> backup file.
            <strong class="text-red-600">
                This will overwrite current data.
            </strong>
        </p>

        <form
            method="post"
            enctype="multipart/form-data"
            class="space-y-3"
            onsubmit="return confirm('This will REPLACE all current data. Continue?');"
        >
            <input
                type="file"
                name="sqlfile"
                accept=".sql,text/plain"
                required
                class="w-full text-sm border rounded-lg px-3 py-2 bg-white"
            >

            <button
                type="submit"
                class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg"
            >
                <i class="fa-solid fa-rotate-left mr-1"></i>
                Restore Database
            </button>
        </form>

        <div class="mt-4 text-xs text-amber-600 bg-amber-50 rounded-lg p-3">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            Download a fresh backup before restoring an older one.
        </div>
    </div>

</div>

<?php page_footer(); ?>