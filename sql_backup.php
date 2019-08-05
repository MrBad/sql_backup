<?php

### the grant syntax for dbdump user 
/**
 * GRANT SELECT ,
 * SHOW DATABASES ,
 * LOCK TABLES ON * . *
 * TO 'dbdump'@'localhost' identified by 'dbdump';
 */

/**
 * Log this message to file
 *
 * @param string $message
 */
function logError($message)
{
    global $logfile;
    echo $message;
    file_put_contents($logfile, $message, FILE_APPEND);
}

/**
 * Recursive removing a dir - just like rm -rf
 *
 * @param string $dir
 * @return bool True on success
 */
function rm_rf($dir)
{
    global $backup_dir;
    if ($dir[strlen($dir) - 1] != '/') {
        $dir .= '/';
    }
    if (!preg_match("'^$backup_dir/'", $dir)) {
        return false;
    }
    if (!is_dir($dir)) {
        return false;
    }
    $dh = opendir($dir);
    if (!$dh) {
        return false;
    }
    while ($file = readdir($dh)) {
        if ($file == '.' || $file == '..') {
            continue;
        }
        if (is_file($dir . $file)) {
            unlink($dir . $file);
        } elseif (is_dir($dir . $file)) {
            rm_rf($dir . $file);
        }
    }
    closedir($dh);
    rmdir($dir);

    return true;
}

/**
 * configurations
 */
date_default_timezone_set('Europe/Bucharest');

$sql_host = '127.0.0.1';
$sql_user = 'MY_DB_USER';
$sql_passwd = 'MY_DB_PASSWORD';

#### where to put the backup scripts ####
$backup_dir = '/tmp/sqlbackup';

#### what directories to exclude from backup ####
$exclude_dbs = array('information_schema', 'mysql', 'performance_schema', 'phpmyadmin', 'sys');

$include_dbs = array();

#### where to put the log ####
$logfile = 'sql_backup.log';

#### how many days to keep the logs before erasing them ####
$keep_max_days = 2;

#### linux paths to mysqldump and tar ####
$mysqldump = '/usr/bin/mysqldump';
$tar = '/bin/tar cvf';


$todel = array();
$dh = opendir($backup_dir);
if (!$dh) {
    logError("Cannot opendir $backup_dir\n");
    die();
}
while ($file = readdir($dh)) {
    if (preg_match("'^([0-9]{2})_([0-9]{2})_([0-9]{4})$'", $file, $match)) {
        $cts = mktime(0, 0, 0, $match[1], $match[2], $match[3]);
        if (time() - $cts > $keep_max_days * 24 * 3600) {
            $todel[] = $backup_dir . '/' . $file . '/';
        }
    }
}
closedir($dh);

foreach ($todel as $dir) {
    echo "Removing old backup: $dir\n";
    rm_rf($dir);
}

rm_rf($backup_dir . '/tmp');

$sql = mysqli_connect($sql_host, $sql_user, $sql_passwd);
if (!$sql) {
    logError("Cannot connect to $sql_host\n");
    die();
}

if (empty($include_dbs)) {
    $query = "SHOW DATABASES";
    $res = mysqli_query($sql, $query);
    if (!$res) {
        logError("Cannot execute [$query]\n");
        die();
    }

    for ($i = 0; $i < mysqli_num_rows($res); $i++) {
        $row = mysqli_fetch_assoc($res);
        if ($row) {
            $db = array_pop($row);
            if (!in_array($db, $exclude_dbs)) {
                $include_dbs[] = $db;
            }
        }
    }
}

if (empty($include_dbs)) {
    logError("No database!\n");
    die();
}

if (!is_dir($backup_dir)) {
    $ret = mkdir($backup_dir, 0700);
    if (!$ret) {
        logError("Cannot create $backup_dir\n");
        die();
    }
}

if (!is_dir($backup_dir . '/tmp/')) {
    $ret = mkdir($backup_dir . '/tmp/', 0700);
    if (!$ret) {
        logError("Cannot create $backup_dir/tmp/\n");
        die();
    }
}

$ret = chdir("$backup_dir/tmp/");
if (!$ret) {
    logError("Cannot chdir to $backup_dir/tmp/\n");
    die();
}
foreach ($include_dbs as $database) {

    echo "[+] Backuping $database\n";


    $query = "USE `$database`";
    $res = mysqli_query($sql, $query);
    if (!$res) {
        logError("Cannot execute [$query]\n");
        die();
    }

    $query = "SHOW TABLES";
    $res = mysqli_query($sql, $query);
    if (!$res) {
        logError("Cannot execute [$query]\n");
        die();
    }
    $tables = array();
    for ($i = 0; $i < mysqli_num_rows($res); $i++) {
        $row = mysqli_fetch_assoc($res);
        if ($row) {
            $table = array_pop($row);
            $tables[] = $table;
        }
    }

    $ret = mkdir("$backup_dir/tmp/$database", 0700);
    if (!$ret) {
        logError("Cannot create $backup_dir/tmp/$database\n");
        die();
    }
    foreach ($tables as $table) {
        echo "\tTable: $table\n";

        $cmd = "$mysqldump --no-autocommit --lock-tables=false -h$sql_host -u$sql_user " . ($sql_passwd != '' ? " -p$sql_passwd" : "")
            . " --opt --skip-extended-insert -C " . " $database $table > "
            . $backup_dir . '/tmp/' . $database . '/' . $table . '.sql'
            . ' 2>/dev/null'
        ;

        exec($cmd);
    }
    $cmd = "$tar $database.tar $database";
    exec($cmd);

    foreach ($tables as $table) {
        unlink("$backup_dir/tmp/$database/$table.sql");
    }
    rmdir("$backup_dir/tmp/$database/");
}

$date = date('m_d_Y', time());
if (!is_dir("$backup_dir/$date")) {
    $ret = mkdir("$backup_dir/$date", 0700);
    if (!$ret) {
        logError("Cannot create $backup_dir/$date\n");
        die();
    }
}

foreach ($include_dbs as $database) {
    copy("$backup_dir/tmp/$database.tar", "$backup_dir/$date/$database.tar");
    unlink("$backup_dir/tmp/$database.tar");
}

#	$cmd="/etc/init.d/mysql restart";
#	exec($cmd);
