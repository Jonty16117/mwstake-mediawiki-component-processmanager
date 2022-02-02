CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/processes (
	`p_pid` VARCHAR(128) NOT NULL,
	`p_state` VARCHAR(255) NOT NULL,
	`p_exitcode` TINYINT UNSIGNED NULL,
	`p_exitstatus` TEXT NULL DEFAULT '',
	`p_started` VARCHAR(14) NULL,
	`p_timeout` INT NOT NULL
);
