# sql_backup
My sql non-incremental backup script
	
	just edit to exclude the unwanted db's
	
	and put a line like this into crontab
		
		### backup the sql database ###
		
		12 03 * * *  /usr/bin/php /root/sql_backup.php

		and on other server just rsync the dir from other cron

		why not gziping the tar archive - induce load, but you can use tar -czvf / tar -cjvf, etc
