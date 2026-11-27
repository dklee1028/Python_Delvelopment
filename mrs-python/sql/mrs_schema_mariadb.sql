-- MRS (Management Report System) 테이블 생성 스크립트 - MariaDB 10.5+
-- 기존 DB 에 mrs_ 접두어 테이블만 추가합니다. 기존 테이블은 변경하지 않습니다.
-- (애플리케이션 최초 기동 시에도 없는 테이블만 자동 생성되므로, DBA 사전 검토/수동 적용용입니다.)

CREATE TABLE IF NOT EXISTS mrs_audit_log (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	at DATETIME NOT NULL, 
	user_id INTEGER, 
	action VARCHAR(50) NOT NULL, 
	target VARCHAR(100) NOT NULL, 
	detail TEXT NOT NULL, 
	PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_audit_log_at ON mrs_audit_log (at);

CREATE TABLE IF NOT EXISTS mrs_customer (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	legacy_customer_id VARCHAR(64), 
	name VARCHAR(200) NOT NULL, 
	address VARCHAR(500) NOT NULL, 
	site VARCHAR(200) NOT NULL, 
	memo TEXT NOT NULL, 
	created_at DATETIME NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_customer_legacy_customer_id ON mrs_customer (legacy_customer_id);

CREATE TABLE IF NOT EXISTS mrs_user (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	username VARCHAR(64) NOT NULL, 
	full_name VARCHAR(100) NOT NULL, 
	email VARCHAR(200) NOT NULL, 
	phone VARCHAR(50) NOT NULL, 
	title VARCHAR(50) NOT NULL, 
	password_hash VARCHAR(255) NOT NULL, 
	`role` VARCHAR(20) NOT NULL, 
	permissions JSON NOT NULL, 
	is_active BOOL NOT NULL, 
	last_login_at DATETIME, 
	created_at DATETIME NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX IF NOT EXISTS ix_mrs_user_username ON mrs_user (username);

CREATE TABLE IF NOT EXISTS mrs_customer_contact (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	customer_id INTEGER NOT NULL, 
	name VARCHAR(100) NOT NULL, 
	department VARCHAR(100) NOT NULL, 
	position VARCHAR(100) NOT NULL, 
	email VARCHAR(200) NOT NULL, 
	phone VARCHAR(50) NOT NULL, 
	receive_reports BOOL NOT NULL, 
	is_primary BOOL NOT NULL, 
	created_at DATETIME NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(customer_id) REFERENCES mrs_customer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_customer_contact_customer_id ON mrs_customer_contact (customer_id);

CREATE TABLE IF NOT EXISTS mrs_equipment (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	customer_id INTEGER NOT NULL, 
	name VARCHAR(100) NOT NULL, 
	vendor VARCHAR(50) NOT NULL, 
	model VARCHAR(100) NOT NULL, 
	serial VARCHAR(200) NOT NULL, 
	os_version VARCHAR(100) NOT NULL, 
	ha_type VARCHAR(50) NOT NULL, 
	disk_info VARCHAR(200) NOT NULL, 
	location VARCHAR(200) NOT NULL, 
	contract_type VARCHAR(100) NOT NULL, 
	memo TEXT NOT NULL, 
	is_active BOOL NOT NULL, 
	created_at DATETIME NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(customer_id) REFERENCES mrs_customer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_equipment_customer_id ON mrs_equipment (customer_id);

CREATE TABLE IF NOT EXISTS mrs_report_template (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	name VARCHAR(200) NOT NULL, 
	report_type VARCHAR(30) NOT NULL, 
	customer_id INTEGER, 
	file_format VARCHAR(10) NOT NULL, 
	stored_path VARCHAR(600) NOT NULL, 
	original_name VARCHAR(300) NOT NULL, 
	description TEXT NOT NULL, 
	placeholders JSON NOT NULL, 
	is_active BOOL NOT NULL, 
	created_at DATETIME NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(customer_id) REFERENCES mrs_customer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_report_template_customer_id ON mrs_report_template (customer_id);

CREATE TABLE IF NOT EXISTS mrs_log_file (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	customer_id INTEGER NOT NULL, 
	equipment_id INTEGER, 
	collected_at DATETIME NOT NULL, 
	original_name VARCHAR(300) NOT NULL, 
	stored_path VARCHAR(600) NOT NULL, 
	size_bytes INTEGER NOT NULL, 
	sha256 VARCHAR(64) NOT NULL, 
	parsed JSON NOT NULL, 
	note VARCHAR(500) NOT NULL, 
	uploaded_by INTEGER, 
	created_at DATETIME NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(customer_id) REFERENCES mrs_customer (id) ON DELETE CASCADE, 
	FOREIGN KEY(equipment_id) REFERENCES mrs_equipment (id) ON DELETE SET NULL, 
	FOREIGN KEY(uploaded_by) REFERENCES mrs_user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_log_file_customer_id ON mrs_log_file (customer_id);
CREATE INDEX IF NOT EXISTS ix_mrs_log_file_equipment_id ON mrs_log_file (equipment_id);
CREATE INDEX IF NOT EXISTS ix_mrs_log_file_collected_at ON mrs_log_file (collected_at);

CREATE TABLE IF NOT EXISTS mrs_report (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	report_no VARCHAR(40) NOT NULL, 
	customer_id INTEGER NOT NULL, 
	report_type VARCHAR(30) NOT NULL, 
	work_subtype VARCHAR(30) NOT NULL, 
	template_id INTEGER, 
	title VARCHAR(300) NOT NULL, 
	variant VARCHAR(10) NOT NULL, 
	status VARCHAR(20) NOT NULL, 
	inspection_date VARCHAR(20) NOT NULL, 
	service_codes JSON NOT NULL, 
	analysis JSON NOT NULL, 
	plan JSON NOT NULL, 
	ai_advice TEXT NOT NULL, 
	ai_model VARCHAR(60) NOT NULL, 
	ai_generated_at DATETIME, 
	diagnosis TEXT NOT NULL, 
	diagnosis_reviewed BOOL NOT NULL, 
	visual_inspection JSON NOT NULL, 
	final_comment TEXT NOT NULL, 
	overall_status VARCHAR(20) NOT NULL, 
	engineer_id INTEGER, 
	created_by INTEGER, 
	completed_at DATETIME, 
	sent_at DATETIME, 
	created_at DATETIME NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(customer_id) REFERENCES mrs_customer (id) ON DELETE CASCADE, 
	FOREIGN KEY(template_id) REFERENCES mrs_report_template (id) ON DELETE SET NULL, 
	FOREIGN KEY(engineer_id) REFERENCES mrs_user (id) ON DELETE SET NULL, 
	FOREIGN KEY(created_by) REFERENCES mrs_user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_report_customer_id ON mrs_report (customer_id);
CREATE UNIQUE INDEX IF NOT EXISTS ix_mrs_report_report_no ON mrs_report (report_no);

CREATE TABLE IF NOT EXISTS mrs_mail_log (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	report_id INTEGER NOT NULL, 
	recipients JSON NOT NULL, 
	cc JSON NOT NULL, 
	subject VARCHAR(300) NOT NULL, 
	status VARCHAR(20) NOT NULL, 
	error TEXT NOT NULL, 
	sent_by INTEGER, 
	created_at DATETIME NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(report_id) REFERENCES mrs_report (id) ON DELETE CASCADE, 
	FOREIGN KEY(sent_by) REFERENCES mrs_user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_mail_log_report_id ON mrs_mail_log (report_id);

CREATE TABLE IF NOT EXISTS mrs_report_equipment (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	report_id INTEGER NOT NULL, 
	equipment_id INTEGER NOT NULL, 
	seq INTEGER NOT NULL, 
	PRIMARY KEY (id), 
	UNIQUE (report_id, equipment_id), 
	FOREIGN KEY(report_id) REFERENCES mrs_report (id) ON DELETE CASCADE, 
	FOREIGN KEY(equipment_id) REFERENCES mrs_equipment (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_report_equipment_report_id ON mrs_report_equipment (report_id);

CREATE TABLE IF NOT EXISTS mrs_report_file (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	report_id INTEGER NOT NULL, 
	file_format VARCHAR(10) NOT NULL, 
	kind VARCHAR(20) NOT NULL, 
	filename VARCHAR(300) NOT NULL, 
	stored_path VARCHAR(600) NOT NULL, 
	size_bytes INTEGER NOT NULL, 
	created_at DATETIME NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(report_id) REFERENCES mrs_report (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_report_file_report_id ON mrs_report_file (report_id);

CREATE TABLE IF NOT EXISTS mrs_report_log (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	report_id INTEGER NOT NULL, 
	log_id INTEGER NOT NULL, 
	PRIMARY KEY (id), 
	UNIQUE (report_id, log_id), 
	FOREIGN KEY(report_id) REFERENCES mrs_report (id) ON DELETE CASCADE, 
	FOREIGN KEY(log_id) REFERENCES mrs_log_file (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS ix_mrs_report_log_report_id ON mrs_report_log (report_id);

