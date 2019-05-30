<?php

namespace Primo\PDOSubclassed;

use Primo\PDOLog\Logs;

class PDO extends \PDO
{

    const logs = true;

    protected $logs; // default stderr
    public $helper;

    static function helperFor($adapter)
    {
        $helperClass = "\\Primo\\DBHelpers\\Helper_{$adapter}";
        if (!class_exists($helperClass)) {
            throw new \PDOException("adapter '{$adapter}' invalid or not yet supported by Primo-PDO");
        }
        return new $helperClass();
    }

    function defaultOptions()
    {
        return [
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_STATEMENT_CLASS => [\Primo\PDOSubclassed\PDOStatement::class, [$this]]
        ];
    }

    function __construct($config, $options = [])
    {

        // 'database' (in config or options) overrides 'name'

        if (!isset($config['database'])) {
            if (isset($options['database'])) $config['database'] = $options['database'];
            else $config['database'] = $config['name'];
        }

        // fix dsn!
        $this->helper = static::helperFor($config['adapter']);
        $dsn = $this->helper->dsn($config);

        $username = isset($config['user']) ? $config['user'] : trim(get_file_contents($config['user_file']));
        $password = isset($config['pass']) ? $config['pass'] : trim(get_file_contents($config['pass_file']));

        $options = array_replace($this->defaultOptions(), $options);

        if (static::logs) $this->logAdd();

        parent::__construct($dsn, $username, $password, $options);
    }

    function getLogs()
    {
        return $this->logs;
    }

    function logAdd($log = null)
    {
        if (!isset($this->logs)) $this->logs = new Logs();
        $this->logs->logAdd($log);
        return $this;
    }

    function logOff()
    {
        $this->logs = null;
        return $this;
    }

    function run($sql = null, ...$args)
    {
        if ($sql === null) return $this;

        if (empty($args)) {
            return $this->query($sql);
        }

        try {
            $stmt = $this->prepare($sql);
        } finally {
            if (!isset($stmt)) error_log("PREPARE FAILED: $sql");
        }
        // handle ("sql with ?", val)
        // handle ("sql with ?", [val])
        // handle ("sql with :name", [':name' => 'val'])
        // handle ("sql with :name", ['name' => 'val'])
        $args = is_array($args[0]) ? $args[0] : $args;

        $success = $stmt->execute($args);

        return $stmt;
    }

    function query($sql)
    {
        try {
            $start = microtime(true);
            $result = parent::query($sql);
        } finally {

            if (isset($this->logs)) {
                $ms = microtime(true) - $start;
                $result = isset($result) ? $result : false;
                $this->logs->logThis($sql, $ms, $result !== false);
            }
        }
        return $result;
    }

    function columnsOfTable($tableName)
    {
        return $this->helper->columnsOfTable($this, $tableName);
    }

}

// REFERENCES
//
// https://phpdelusions.net/pdo/pdo_wrapper
// https://github.com/paragonie/easydb
// https://www.reddit.com/r/PHP/comments/9i74mj/github_paragonieeasydbcache_easydb_with_inmemory/
// https://gist.github.com/rquadling/942253b0ccebd2a0a3c3d6030524fdb0