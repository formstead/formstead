CREATE TABLE {{prefix}}formstead_forms (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(19) NOT NULL,
				name varchar(190) NOT NULL,
				is_enabled tinyint(1) NOT NULL DEFAULT 1,
				fields longtext NOT NULL,
				schema_version int(10) unsigned NOT NULL DEFAULT 1,
				success_url varchar(2048) NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id)
			) {{charset}} ENGINE=InnoDB;

CREATE TABLE {{prefix}}formstead_submissions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				schema_version int(10) unsigned NOT NULL,
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'new',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY form_status_created (form_id,status,created_at)
			) {{charset}} ENGINE=InnoDB;
