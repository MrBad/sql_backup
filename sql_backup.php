<?php

### the grant syntax for dbdump user 
/**
 * 	GRANT SELECT ,
	SHOW DATABASES ,
	LOCK TABLES ON * . * 
	TO 'dbdump'@'localhost' identified by 'dbdump';
 */
	### some logging ###
	function logerror($msg) {
		global $logfile;
		echo $msg;
		file_put_contents($logfile, $msg, FILE_APPEND);
	}

	### recursive removing a dir - just like rm -rf ###
	function rm_rf($dir) {
		global $backup_dir;
		if ($dir[strlen($dir)-1] != '/') {
			$dir.='/';
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
		while ($file=readdir($dh)) {
			if ($file=='.'||$file=='..') {
				continue;
			}
			if (is_file($dir.$file)) {
				unlink($dir.$file);
			} elseif (is_dir($dir.$file)) {
				rm_rf($dir.$file);
			}
		}
		closedir($dh);
		rmdir($dir);
		return true;
	}
	
	#### configurationis ####
	date_default_timezone_set('Europe/Bucharest');
	
	$sql_host='localhost';
	$sql_user='dbdump';
	$sql_passwd='MYDBDUMP_PASS';

	#### where to put the backup scripts ####
	$backup_dir='/home/sqlbackup';

	#### what directories to exclude from backup ####
	$exclude_dbs=array('mysql','information_schema','muci');
	
	$include_dbs=array();
	
	#### where to put the log ####
	$logfile='sql_backup.log';
	
	#### how many days to keep the logs before erasing them ####
	$keep_max_days = 2;
	
	#### linux paths to mysqldump and tar ####
	$mysqldump='/usr/bin/mysqldump';
	$tar = '/bin/tar cvf';
	
	
	$todel = array();
	$dh = opendir($backup_dir);
	if (!$dh) {
		logerror("Cannot opendir $backup_dir\n");
		die();
	}
	while ($file=readdir($dh)) {
		if (preg_match("'^([0-9]{2})_([0-9]{2})_([0-9]{4})$'", $file, $match)) {
			$cts = mktime(0,0,0,$match[1],$match[2],$match[3]);
			if (time() - $cts > $keep_max_days * 24 * 3600) {
				$todel[] = $backup_dir.'/'.$file.'/';
			}
		}
	}
	closedir($dh);
	
	foreach ($todel as $dir) {
		echo "Removing old backup: $dir\n";
		rm_rf($dir);
	}
	
	rm_rf($backup_dir.'/tmp');
	
	$sql = mysql_connect($sql_host,$sql_user,$sql_passwd);
	if (!$sql) {
		logerror("Cannot connect to $sql_host\n");
		die();
	}
	
	if (empty($include_dbs)) {
		$query="SHOW DATABASES";
		$res = mysql_query($query);
		if (! $res) {
			logerror("Cannot execute [$query]\n");
			die();
		}
//		echo mysql_num_rows($res);
		for ($i=0; $i < mysql_num_rows($res); $i++){
			$row = mysql_fetch_assoc($res);
			if ($row) {
				$db = array_pop($row);
				if (!in_array($db, $exclude_dbs)) {
				 	$include_dbs[] = $db;
				}
			}
		}
	}
	
	if (empty($include_dbs)) {
		logerror("No database!\n");
		die();
	}
	
	if (!is_dir($backup_dir)) {
		$ret = mkdir($backup_dir,0700);
		if (!$ret) {
			logerror("Cannot create $backup_dir\n");
			die();
		}
	}
	
	if (!is_dir($backup_dir.'/tmp/')) {
		$ret = mkdir($backup_dir.'/tmp/',0700);
		if (! $ret) {
			logerror("Cannot create $backup_dir/tmp/\n");
			die();
		}
	}
	
	$ret = chdir("$backup_dir/tmp/");
	if (!$ret) {
		logerror("Cannot chdir to $backup_dir/tmp/\n");
		die();
	}
	foreach ($include_dbs as $database) {
		
		echo "[+] Backuping $database\n";
		
		
		$query="USE `$database`";
		$res = mysql_query($query);
		if (!$res) {
			logerror("Cannot execute [$query]\n");
			die();
		}
		
		$query="SHOW TABLES";
		$res = mysql_query($query);
		if (!$res) {
			logerror("Cannot execute [$query]\n");
			die();
		}
		$tables = array();
		for ($i=0; $i < mysql_num_rows($res); $i++){
			$row = mysql_fetch_assoc($res);
			if ($row) {
				$table = array_pop($row);
				$tables[] = $table;
			}
		}
		
		$ret = mkdir("$backup_dir/tmp/$database",0700);
		if (! $ret) {
			logerror("Cannot create $backup_dir/tmp/$database\n");
			die();
		}
		foreach ($tables as $table) {
			echo "\tTable: $table\n";
			
			$cmd = "$mysqldump --no-autocommit --lock-tables=false -h$sql_host -u$sql_user "
			 . ($sql_passwd!=''?" -p$sql_passwd":"")
			 . " --opt --skip-extended-insert -C " 
			 . " $database $table > " 
			 . $backup_dir.'/tmp/'.$database.'/'.$table.'.sql';
			
			exec($cmd);
		}
		$cmd = "$tar $database.tar $database";
		exec($cmd);
		
		foreach ($tables as $table) {
			unlink("$backup_dir/tmp/$database/$table.sql");
		}
		rmdir("$backup_dir/tmp/$database/");
	}
	
	$date=date('m_d_Y',time());
	if (!is_dir("$backup_dir/$date")) {
		$ret = mkdir("$backup_dir/$date",0700);
		if (! $ret) {
			logerror("Cannot create $backup_dir/$date\n");
			die();
		}
	}
	
	foreach ($include_dbs as $database) {
		copy("$backup_dir/tmp/$database.tar", "$backup_dir/$date/$database.tar");
		unlink("$backup_dir/tmp/$database.tar");	
	}
	
#	$cmd="/etc/init.d/mysql restart";
#	exec($cmd);

?>
