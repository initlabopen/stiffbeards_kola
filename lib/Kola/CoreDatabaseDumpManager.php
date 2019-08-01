<?php

namespace Kola;

class CoreDatabaseDumpManager
{
    /** @var PostgresReloader */
    protected $reloader;
    protected $coreDir;

    protected $restoreNumber = 0;

    protected $isSymfonyCore = true;

    public function __construct($coreDir = null, $dbUri = null)
    {
        if ($coreDir === null) {
            // if kola was used as a composer package,
            // we're now in <instance>/<package>/vendor/stiffbeards/kola/lib/Kola
            // we want <instance>/core
            $coreDir = __DIR__ . '/../../../../../../core';
        }

        $this->coreDir = $coreDir;

        $this->isSymfonyCore = !file_exists($this->coreDir . '/cli/clean-cache.php');

        if ($dbUri === null) {
            $dbUri = $this->getDbUriFromCore();
        }

        $this->reloader = new PostgresReloader($dbUri);
        $this->reloader->saveProduction();

        register_shutdown_function(function () {
            $this->reloader->restoreProduction();
        });
    }

    public function clean($saveCleanState = true)
    {
        if ($saveCleanState) {
            $this->restore('clean', function () {
                $this->clean(false);
            });
            return;
        }

        $this->restoreNumber++;
        $this->cleanMemcache();
        $this->callCoreDumper('clean');
    }

    public function restore($name, callable $stateDescription)
    {
        $this->restoreNumber++;

        $this->cleanMemcache();

        if ($this->reloader->restore($name)) {
            return;
        }

        $currentLevel = $this->restoreNumber;

        call_user_func($stateDescription);

        if ($currentLevel == $this->restoreNumber) {
            throw new \LogicException("restore() callback for '{$name}' needs a parent restore or a clean(), otherwise you will save a dirty database state");
        }

        $this->reloader->save($name);
    }

    protected function callCoreDumper($args)
    {
        if ($this->isSymfonyCore) {
            exec($this->coreDir . '/bin/test-db.sh ' . $args . ' 2>&1', $out, $err);
        } else {
            if ($args != 'clean') {
                throw new \LogicException("Core dumper only supports 'clean' now");
            }

            exec('php ' . escapeshellarg($this->coreDir . '/cli/init-db.php') . ' 2>&1', $out, $err);
        }
        if ($err) {
            throw new \Exception("Calling core/bin/test-db.sh {$args} failed\n" . join("\n", $out));
        }
    }

    protected function cleanMemcache()
    {
        // TODO чистку кеша между тестами надо сделать как-то универсально на уровне пакетов
        if ($this->isSymfonyCore) {
            exec($this->coreDir . '/sf grace:clean_cache > /dev/null');
        } else {
            exec('php ' . escapeshellarg($this->coreDir . '/cli/clean-cache.php') . ' > /dev/null');
        }
    }

    protected function getDbUriFromCore()
    {
        if (!$this->isSymfonyCore) {
            $ini = parse_ini_file($this->coreDir . '/parameters.ini');
            return $ini['db.uri'];
        }

        $parts = array();

        // YML parsing is easy
        $isOnLocation = false;
        $indent       = null;
        foreach (file($this->coreDir . '/local/parameters.yml') as $line) {
            if (trim($line) == 'grace_db:') {
                $isOnLocation = true;
            } else if ($isOnLocation) {
                if (!preg_match('/^(\s+)(\S+):\s*([^#\s]+)/', $line, $match)) {
                    break;
                }
                if ($indent === null) {
                    $indent = $match[1];
                } else if ($indent != $match[1]) {
                    break;
                }

                $parts[$match[2]] = $match[3];
            }
        }

        return 'pgsql://'
            . urlencode($parts['user']) . ':' . urlencode($parts['password'])
            . '@'
            . urlencode($parts['host']) . ':' . urlencode($parts['port'])
            . '/'
            . urlencode($parts['database'])
        ;
    }
}
