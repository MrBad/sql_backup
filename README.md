# sql_backup
My sql non-incremental backup script
	just setup to exlude the unwanted db's
	and put a line like this into crontab
		### backup the sql database ###
		12 03 * * *  /usr/bin/php /root/sql_backup.php
