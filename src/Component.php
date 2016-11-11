<?php

namespace BootPress\SQLite;

use BootPress\Database\Component as Database;

class Component extends Database
{
    /** @var object A BootPress\SQLite\Fts instance. */
    public $fts;

    /** @var bool Whether or not this is a new database. */
    public $created = false;

    /** @var array The database's settings. */
    private $info = array();

    /**
     * Connects to an SQLite database **$file**, and creates one if it doesn't already exist.
     *
     * @param string $file The SQLite database file location, or just leave it ``null`` to create an SQLite database in *':memory:'*.
     */
    public function __construct($file = null)
    {
        if (is_null($file)) {
            $file = ':memory:';
            $this->created = true;
        } else {
            if (!is_file($file)) {
                if (!is_dir(dirname($file))) {
                    mkdir(dirname($file), 0755, true);
                }
                $this->created = true;
            }
            if (class_exists('BootPress\Page\Component') && class_exists('Symfony\Component\Yaml\Yaml')) {
                $page = \BootPress\Page\Component::html();
                $databases = $page->file('databases.yml');
                $yaml = (is_file($databases)) ? \Symfony\Component\Yaml\Yaml::parse(file_get_contents($databases)) : array();
                if (!isset($yaml['sqlite'])) {
                    $yaml['sqlite'] = array();
                }
                if (!in_array($file, $yaml['sqlite'])) {
                    $yaml['sqlite'][] = $file;
                    ksort($yaml);
                    sort($yaml['sqlite']);
                    $yaml = \Symfony\Component\Yaml\Yaml::dump($yaml, 3);
                    file_put_contents($databases, $yaml);
                }
            }
        }
        $this->driver($file);
        $this->connection = new \SQLite3($file);
        $this->connection->exec('PRAGMA foreign_keys = ON');
        $this->fts = new Fts($this);
    }

    /**
     * Either create an SQLite **$table** if it has not already been created, or verify that it matches the sqlite_master table index. If something has changed, then the **$table** will be altered accordingly.
     *
     * @param string $table   The database table name.
     * @param array  $fields  An ``array($name => $type, ...)`` of fields that define this **$table**.
     * @param mixed  $index   A string of comma-separated '**field, name, ...**'s to index. If you are creating multiple indexes, then make this an ``array('field', 'name', ... )`` of all the indexes you would like to create. If it is a unique index, then make it an ``array('unique' => 'field, name, ...')``.
     * @param array  $changes When changing field names, make an ``array($old => $new, ...)`` to map the old field name with the new field name, so that all of the data is updated accordingly. You can change the **$fields** order and type no problem, but if you change field names and don't map them, we'll send the old (missing) field name to the scrapheap, and every new field name will receive it's default value.
     *
     * @return bool Either ``false`` if nothing has changed and the **$table** is exactly as you would like it, or ``true`` if the **$table** has been newly created or updated in any way.
     *
     * @example
     *
     * ```php
     * if ($db->created) {
     *
     *     $db->create('employees', array(
     *         'id' => 'INTEGER PRIMARY KEY',
     *         'name' => 'TEXT COLLATE NOCASE',
     *         'position' => 'TEXT NOT NULL DEFAULT ""',
     *     ), array('unique'=>'position'));
     *
     *     // Wait, I just changed my mind
     *     $db->create('employees', array(
     *         'id' => 'INTEGER PRIMARY KEY',
     *         'name' => 'TEXT UNIQUE COLLATE NOCASE',
     *         'title' => 'TEXT DEFAULT ""',
     *     ), 'title', array(
     *         'position' => 'title',
     *     ));
     *
     * }
     * ```
     */
    public function create($table, array $fields, $index = array(), array $changes = array())
    {
        $columns = array();
        foreach ($fields as $name => $type) {
            $columns[] = (is_int($name)) ? $type : $name.' '.$type;
        }
        $columns = implode(", \n\t", $columns);
        $query = 'CREATE TABLE '.$table.' ('.$columns.')';
        $executed = $this->info('tables', $table);
        // See http://www.sqlite.org/fileformat2.html - 2.5 Storage Of The SQL Database Schema
        if (preg_replace('/(\'|")/', '', $query) == preg_replace('/(\'|")/', '', $executed)) {
            $this->index($table, $index); // make sure they are all correct also
            return false; // the table has already been created in it's requested state
        }
        $this->info('tables', $table, $query); // to either add or update
        if ($executed) { // then this table is being altered in some way
            $this->index($table, '');
            $this->alter($table, $fields, $changes, $columns);
        } else {
            $this->exec($query); // We should only get here once
        }
        $this->index($table, $index);

        return true; // the table has been created (or altered)
    }

    /**
     * Create and retrieve database settings.
     *
     * @param string $name  What you want to either set or return.
     * @param mixed  $value The setting's value. If you want to remove the setting, then set this explicitly to ``null``.
     *
     * @return mixed An array of all the settings (if no parameters are given), or the setting's value (if no value is given).
     *
     * @example
     *
     * ```php
     * $db->settings('version', '1.2');
     *
     * echo $db->settings('version'); // 1.2
     * ```
     */
    public function settings($name = null, $value = null)
    {
        switch (func_num_args()) {
            case 0: // they want it all
                return $this->info('settings');
                break;
            case 1: // they want to retrieve a specific setting
                return $this->info('settings', func_get_arg(0));
                break;
            case 2: // they want to establish a setting
                $update = false;
                list($name, $value) = func_get_args();
                $current = $this->info('settings', $name);
                if (is_null($value)) {
                    // then we don't want this in the database as "null" is the default value
                    if (!is_null($current)) {
                        unset($this->info['settings'][$name]);
                        $update = true;
                    }
                } elseif ($current !== $value) {
                    $this->info['settings'][$name] = $value;
                    $update = true;
                }
                if ($update) {
                    $this->exec('UPDATE config SET settings = ?', serialize($this->info['settings']));
                }
                break;
        }
    }

    /**
     * Returns the ``$field . 'IN(' . implode(',', $ids) . ')'``, in the same order given.  This method is patterned after using MySQL's [FIELD()](http://dba.stackexchange.com/questions/109120/how-does-order-by-field-in-mysql-work-internally) function, which unfortunately is unavailable in SQLite.
     *
     * @param string $field Database column.
     * @param array  $ids   Those passed to the IN() clause.
     *
     * @return string An SQL string to put at the end of your query.
     *
     * @example
     *
     * ```php
     * if ($ids = $db->ids('SELECT id FROM employees ORDER BY name ASC')) {
     *     foreach ($db->all(array(
     *         'SELECT name, title FROM employees',
     *         'WHERE '.$db->inOrder('id', $ids),
     *     )) as $row) {
     *         list($name, $title) = $row;
     *     }
     * }
     * ```
     */
    public function inOrder($field, array $ids)
    {
        $sql = $field.' IN('.implode(',', $ids).') ORDER BY CASE '.$field;
        foreach (array_values($ids) as $num => $id) {
            $sql .= ' WHEN '.$id.' THEN '.$num;
        }
        $sql .= ' ELSE NULL END ASC';

        return $sql;
    }

    /**
     * When you overwhelm an SQLite database with overlapping inserts and updates, there's a chance it may become corrupted.  If it does, I've been able to recreate it using this method.
     *
     * @param string $file The **NEW** SQLite database file location.  The one you want to create.
     */
    public function recreate($file)
    {
        if (is_file($file)) {
            return;
        }
        $virtual = $tables = $indexes = array();
        if ($result = $this->query('SELECT type, name, sql FROM sqlite_master')) {
            while (list($type, $name, $sql) = $this->fetch($result)) {
                if (!empty($sql)) {
                    switch ($type) {
                        case 'table':
                            $tables[$name] = $sql;
                            break;
                        case 'index':
                            $indexes[] = $sql;
                            break;
                    }
                }
            }
            $this->close($result);
        }
        foreach ($tables as $name => $sql) {
            if (strpos($sql, 'VIRTUAL TABLE')) {
                $virtual[] = $name;
            }
        }
        foreach ($virtual as $table) {
            foreach ($tables as $name => $sql) {
                if (strpos($name, "{$table}_") === 0) {
                    unset($tables[$name]);
                }
            }
        }
        $db = new self($file);
        $this->exec('ATTACH DATABASE '.$this->dbEscape($file).' AS recreate');
        foreach ($tables as $table => $sql) {
            $db->connection()->exec($sql);
            if ($fields = $this->row('SELECT * FROM '.$table.' LIMIT 1', '', 'assoc')) {
                $fields = implode(', ', array_keys($fields));
                $this->exec("INSERT INTO recreate.{$table} ({$fields}) SELECT * FROM {$table}");
            }
        }
        foreach ($indexes as $sql) {
            $db->connection()->exec($sql);
        }
        $db->connection()->close();
    }

    private function alter($table, array $fields, array $changes, $columns)
    {
        $map = array();
        if ($compare = $this->row('SELECT * FROM '.$table.' LIMIT 1', array(), 'assoc')) {
            foreach ($changes as $old => $new) {
                if (isset($fields[$new]) && isset($compare[$old])) {
                    $map[$old] = $new; // legitimate changes
                }
            }
            foreach (array_keys($compare) as $field) {
                if (isset($fields[$field]) && !isset($map[$field])) {
                    $map[$field] = $field; // old fields that match the new
                }
            }
        }
        $this->connection->exec('PRAGMA foreign_keys = OFF');
        $this->connection->exec('BEGIN IMMEDIATE');
        $result = true;
        if ($result !== false) {
            $result = $this->exec("CREATE TABLE {$table}_copy ({$columns})");
        }
        if (!empty($map)) {
            $new = implode(', ', array_values($map));
            $old = implode(', ', array_keys($map));
            if ($result !== false) {
                $result = $this->exec("INSERT INTO {$table}_copy ({$new}) SELECT {$old} FROM {$table}");
            }
        }
        if ($result !== false) {
            $result = $this->exec("DROP TABLE {$table}");
        }
        if ($result !== false) {
            $result = $this->exec("ALTER TABLE {$table}_copy RENAME TO {$table}");
        }
        $this->connection->exec($result !== false ? 'COMMIT' : 'ROLLBACK');
        $this->connection->exec('PRAGMA foreign_keys = ON');
    }

    private function index($table, $columns)
    {
        $queries = array();
        $outdated = $this->info('indexes', $table);
        if (empty($outdated)) {
            $outdated = array();
        }
        if (!empty($columns)) {
            foreach ((array) $columns as $key => $indexes) {
                $unique = (!is_int($key) && strtolower($key) == 'unique') ? ' UNIQUE ' : ' ';
                $indexes = array_map('trim', explode(',', $indexes));
                $name = $table.'_'.implode('_', $indexes);
                $sql = "CREATE{$unique}INDEX {$name} ON {$table} (".implode(', ', $indexes).')';
                $queries[$name] = $sql;
                if (!isset($outdated[$name]) || $outdated[$name] != $sql) {
                    if (isset($outdated[$name])) {
                        $this->exec('DROP INDEX '.$name);
                    }
                    $this->exec($sql);
                }
            }
            foreach ($outdated as $name => $sql) {
                if (!isset($queries[$name])) {
                    $this->exec('DROP INDEX '.$name);
                }
            }
            $this->info('indexes', $table, $queries);
        }
    }

    /**
     * Only made public so that ``$this->fts`` can call it, and it's not documented to discourage it's use.
     */
    public function info($master) // only made public so that $this->fts can call it
    {
        if ($master == 'settings') {
            if (!isset($this->info['settings'])) {
                if ($this->create('config', array('settings' => 'TEXT NOT NULL DEFAULT ""'))) {
                    $this->exec('INSERT INTO config (settings) VALUES (?)', serialize(array()));
                }
                $this->info['settings'] = array();
                if ($settings = $this->value('SELECT settings FROM config')) {
                    $this->info['settings'] = unserialize($settings);
                }
            }
        } elseif (!isset($this->info[$master])) { // 'tables' or 'indexes'
            if ($result = $this->query('SELECT type, name, tbl_name, sql FROM sqlite_master')) {
                while (list($type, $name, $table, $sql) = $this->fetch($result)) {
                    switch ($type) {
                        case 'table':
                            $this->info['tables'][$table] = $sql;
                            break;
                        case 'index':
                            if (!empty($sql)) {
                                $this->info['indexes'][$table][$name] = $sql;
                            }
                            break;
                    }
                }
                $this->close($result);
            }
        }
        switch (func_num_args()) {
            case 3:
                list($master, $name, $add) = func_get_args();
                $this->info[$master][$name] = $add;
                break;
            case 2:
                list($master, $name) = func_get_args();

                return (isset($this->info[$master][$name])) ? $this->info[$master][$name] : null;
                break;
            case 1:
                list($master) = func_get_args();

                return (isset($this->info[$master])) ? $this->info[$master] : null;
                break;
        }
    }

    protected function dbPrepare($query)
    {
        try {
            return $this->connection->prepare($query); // returns (mixed) $stmt object or false
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function dbPrepareError()
    {
        return ($msg = $this->connection->lastErrorMsg()) ? 'Code: '.$this->connection->lastErrorCode()." Error: {$msg}" : false; // returns (string) error or false
    }

    protected function dbExecute($stmt, array $values, $reference)
    {
        if (isset($this->prepared[$reference]['result'])) {
            $this->prepared[$reference]['result']->finalize();
            unset($this->prepared[$reference]['result']);
            $stmt->reset();
        }
        foreach (array_values($values) as $key => $value) {
            switch (gettype($value)) {
                case 'boolean':
                case 'integer':
                    $type = SQLITE3_INTEGER;
                    break;
                case 'double':
                    $type = SQLITE3_FLOAT;
                    break;
                case 'NULL':
                    $type = SQLITE3_NULL;
                    break;
                default:
                    $type = SQLITE3_TEXT;
                    break;
            }
            $stmt->bindValue($key + 1, $value, $type);
        }
        // Throws an ErrorException when a constraint fails eg. a (datatype mismatch)[https://www.sqlite.org/rescode.html#mismatch]
        try {
            if ($object = $stmt->execute()) {
                $this->prepared[$reference]['result'] = $object;
            }
        } catch (\Exception $e) {
            return false;
        }

        return ($object) ? true : false; // returns (bool) true or false
    }

    protected function dbExecuteError($stmt)
    {
        return $this->dbPrepareError(); // returns (string) error or false
    }

    protected function dbStyle($fetch)
    {
        switch ($fetch) {
            case 'assoc':
                return \SQLITE3_ASSOC;
                break;
            case 'both':
                return \SQLITE3_BOTH;
                break;
            default:
                return \SQLITE3_NUM;
                break;
        }
    }

    protected function dbFetch($stmt, $style, $reference)
    {
        return (isset($this->prepared[$reference]['result'])) ? $this->prepared[$reference]['result']->fetchArray($style) : false; // returns (mixed) $style or false
    }

    protected function dbInserted()
    {
        return $this->connection->lastInsertRowID(); // returns (int) last inserted row id or sequence value
    }

    protected function dbAffected($stmt)
    {
        return $this->connection->changes(); // returns (int) number of rows affected by last $stmt
    }

    protected function dbClose($stmt, $reference)
    {
        if (isset($this->prepared[$reference]['result'])) {
            $this->prepared[$reference]['result']->finalize();
            $this->prepared[$reference]['result'] = null;
        }

        return $stmt->close(); // returns (bool) true or false
    }

    protected function dbEscape($string)
    {
        return (is_numeric($string)) ? $string : "'".$this->connection->escapeString($string)."'";
    }
}
