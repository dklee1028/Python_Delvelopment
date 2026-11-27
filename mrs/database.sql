-- MRS 테이블 참고용 DDL (MariaDB, 스키마 v2). install.php 와 첫 접속 시 자동으로 만들고/추가하므로 직접 실행할 필요는 없습니다.
-- 기존 pms_ 테이블은 건드리지 않고 mrs_ 테이블만 추가합니다.
-- v1 에서 올릴 때 자동으로 추가되는 열: mrs_equipment(disposed_at, dispose_note), mrs_log_file(status, n_crit, n_warn)

CREATE TABLE IF NOT EXISTS mrs_user (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL UNIQUE, full_name VARCHAR(100), email VARCHAR(200), phone VARCHAR(50),
        title VARCHAR(50), password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'engineer', permissions TEXT, is_active INT NOT NULL DEFAULT 1,
        must_change_pw INT NOT NULL DEFAULT 0, fail_count INT NOT NULL DEFAULT 0, locked_until VARCHAR(19), last_login_at VARCHAR(19), created_at VARCHAR(19), updated_at VARCHAR(19)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_customer (id INT AUTO_INCREMENT PRIMARY KEY, legacy_customer_id VARCHAR(64), name VARCHAR(200) NOT NULL, address VARCHAR(500), site VARCHAR(200),
        memo TEXT, created_at VARCHAR(19), updated_at VARCHAR(19)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_customer_contact (id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, name VARCHAR(100) NOT NULL, department VARCHAR(100), position VARCHAR(100),
        email VARCHAR(200), phone VARCHAR(50), receive_reports INT NOT NULL DEFAULT 1, is_primary INT NOT NULL DEFAULT 0, created_at VARCHAR(19), updated_at VARCHAR(19)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_equipment (id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, name VARCHAR(100) NOT NULL, vendor VARCHAR(50), model VARCHAR(100), serial VARCHAR(200),
        os_version VARCHAR(100), ha_type VARCHAR(50), disk_info VARCHAR(200), location VARCHAR(200), contract_type VARCHAR(100), memo TEXT, is_active INT NOT NULL DEFAULT 1,
        disposed_at VARCHAR(19), dispose_note VARCHAR(500), created_at VARCHAR(19), updated_at VARCHAR(19)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_log_file (id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, equipment_id INT, collected_at VARCHAR(19), original_name VARCHAR(300),
        stored_path VARCHAR(600), size_bytes INT NOT NULL DEFAULT 0, sha256 VARCHAR(64), parsed MEDIUMTEXT, note VARCHAR(500), uploaded_by INT,
        status VARCHAR(20), n_crit INT, n_warn INT, created_at VARCHAR(19), updated_at VARCHAR(19)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_customer_assign (id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, user_id INT NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'sub', created_at VARCHAR(19)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_report_template (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, report_type VARCHAR(30), customer_id INT, file_format VARCHAR(10),
        stored_path VARCHAR(600), original_name VARCHAR(300), description TEXT, placeholders TEXT, is_active INT NOT NULL DEFAULT 1, created_at VARCHAR(19), updated_at VARCHAR(19)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_report (id INT AUTO_INCREMENT PRIMARY KEY, report_no VARCHAR(40) NOT NULL UNIQUE, customer_id INT NOT NULL, report_type VARCHAR(30), work_subtype VARCHAR(30),
        template_id INT, title VARCHAR(300), variant VARCHAR(10), status VARCHAR(20) NOT NULL DEFAULT 'draft', inspection_date VARCHAR(20), service_codes TEXT,
        analysis MEDIUMTEXT, plan MEDIUMTEXT, ai_advice TEXT, ai_model VARCHAR(60), ai_generated_at VARCHAR(19), diagnosis TEXT, diagnosis_reviewed INT NOT NULL DEFAULT 0,
        visual_inspection TEXT, final_comment TEXT, overall_status VARCHAR(20), engineer_id INT, created_by INT, completed_at VARCHAR(19), sent_at VARCHAR(19),
        created_at VARCHAR(19), updated_at VARCHAR(19)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_report_equipment (id INT AUTO_INCREMENT PRIMARY KEY, report_id INT NOT NULL, equipment_id INT NOT NULL, seq INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_report_log (id INT AUTO_INCREMENT PRIMARY KEY, report_id INT NOT NULL, log_id INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_report_file (id INT AUTO_INCREMENT PRIMARY KEY, report_id INT NOT NULL, file_format VARCHAR(10), kind VARCHAR(20), filename VARCHAR(300),
        stored_path VARCHAR(600), size_bytes INT NOT NULL DEFAULT 0, created_at VARCHAR(19)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_mail_log (id INT AUTO_INCREMENT PRIMARY KEY, report_id INT NOT NULL, recipients TEXT, cc TEXT, subject VARCHAR(300), status VARCHAR(20), error TEXT,
        sent_by INT, created_at VARCHAR(19)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_audit_log (id INT AUTO_INCREMENT PRIMARY KEY, at VARCHAR(19), user_id INT, user_name VARCHAR(110), action VARCHAR(50), target VARCHAR(150), detail TEXT, ip VARCHAR(45)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mrs_setting (k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT, is_secret INT NOT NULL DEFAULT 0, updated_at VARCHAR(19), updated_by INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX ix_mrs_cust_legacy ON mrs_customer (legacy_customer_id);

CREATE INDEX ix_mrs_contact_c ON mrs_customer_contact (customer_id);

CREATE INDEX ix_mrs_eq_c ON mrs_equipment (customer_id);

CREATE INDEX ix_mrs_log_c ON mrs_log_file (customer_id);

CREATE INDEX ix_mrs_log_e ON mrs_log_file (equipment_id);

CREATE INDEX ix_mrs_log_at ON mrs_log_file (collected_at);

CREATE INDEX ix_mrs_rep_c ON mrs_report (customer_id);

CREATE INDEX ix_mrs_rep_st ON mrs_report (status);

CREATE UNIQUE INDEX ux_mrs_rep_eq ON mrs_report_equipment (report_id, equipment_id);

CREATE UNIQUE INDEX ux_mrs_rep_log ON mrs_report_log (report_id, log_id);

CREATE INDEX ix_mrs_rfile_r ON mrs_report_file (report_id);

CREATE UNIQUE INDEX ux_mrs_assign ON mrs_customer_assign (customer_id, user_id);

CREATE INDEX ix_mrs_assign_u ON mrs_customer_assign (user_id);

CREATE INDEX ix_mrs_mail_r ON mrs_mail_log (report_id);

CREATE INDEX ix_mrs_audit_at ON mrs_audit_log (at);

