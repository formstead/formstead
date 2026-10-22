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
				allowed_origins text,
				rate_per_minute smallint(5) unsigned DEFAULT NULL,
				rate_per_hour int(10) unsigned DEFAULT NULL,
				honeypot_rejections bigint(20) unsigned NOT NULL DEFAULT 0,
				notify_recipients text,
				reply_to_field varchar(64) DEFAULT NULL,
				mail_include_content tinyint(1) NOT NULL DEFAULT 0,
				retention_days smallint(5) unsigned NOT NULL DEFAULT 30,
				identity_fields text,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id)
			) {{charset}} ENGINE=InnoDB;

CREATE TABLE {{prefix}}formstead_form_versions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL,
				fields longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY form_version (form_id,version)
			) {{charset}} ENGINE=InnoDB;

CREATE TABLE {{prefix}}formstead_submissions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				schema_version int(10) unsigned NOT NULL,
				receipt_id varchar(20) DEFAULT NULL,
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'new',
				created_at datetime NOT NULL,
				content_hash char(64) DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY receipt_id (receipt_id),
				KEY form_status_created (form_id,status,created_at),
				KEY form_created (form_id,created_at),
				KEY status_created (status,created_at),
				KEY form_content (form_id,content_hash,created_at)
			) {{charset}} ENGINE=InnoDB;

CREATE TABLE {{prefix}}formstead_rate_limits (
				bucket char(64) NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				expires_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (bucket),
				KEY expires_at (expires_at)
			) {{charset}} ENGINE=InnoDB;

CREATE TABLE {{prefix}}formstead_idempotency_keys (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				key_hash char(64) NOT NULL,
				body_hash char(64) NOT NULL,
				submission_id bigint(20) unsigned NOT NULL,
				expires_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY form_key (form_id,key_hash),
				KEY expires_at (expires_at),
				KEY submission_id (submission_id)
			) {{charset}} ENGINE=InnoDB;

CREATE TABLE {{prefix}}formstead_deliveries (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				submission_id bigint(20) unsigned NOT NULL,
				channel varchar(20) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_attempt_at datetime NOT NULL,
				locked_until datetime DEFAULT NULL,
				lock_token char(32) DEFAULT NULL,
				last_error varchar(64) DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_next (status,next_attempt_at),
				KEY submission_id (submission_id),
				KEY status_updated (status,updated_at)
			) {{charset}} ENGINE=InnoDB;

CREATE TABLE {{prefix}}formstead_identities (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				submission_id bigint(20) unsigned NOT NULL,
				form_id bigint(20) unsigned NOT NULL,
				field varchar(64) NOT NULL,
				value_hash char(64) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY hash_submission (value_hash,submission_id),
				KEY submission_id (submission_id),
				KEY form_field (form_id,field)
			) {{charset}} ENGINE=InnoDB;

CREATE TABLE {{prefix}}formstead_audit (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event varchar(40) NOT NULL,
				actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_type varchar(20) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				context text,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY event_created (event,created_at)
			) {{charset}} ENGINE=InnoDB;
