-- EMPOWER Database Schema (MySQL 8+) for Laravel 12
-- Project: Recruitment, Deployment, and Competency-Based Job Matching DSS
-- Client: CDE Manpower Services
--
-- Notes:
-- 1) This script is migration-ready by module and naming convention.
-- 2) In Laravel, split each CREATE TABLE block into its own migration file.
-- 3) Use unsignedBigInteger for *_id columns and add indexes as defined.
-- 4) Payroll tables are intentionally excluded.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    suffix VARCHAR(20) NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    mobile_number VARCHAR(30) NULL,
    password VARCHAR(255) NOT NULL,
    user_type VARCHAR(30) NOT NULL DEFAULT 'hr', -- admin, hr, applicant, employee
    applicant_id BIGINT UNSIGNED NULL,
    employee_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    email_verified_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    INDEX idx_users_user_type (user_type),
    INDEX idx_users_active (is_active),
    INDEX idx_users_applicant (applicant_id),
    INDEX idx_users_employee (employee_id)
) ENGINE=InnoDB;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(80) NOT NULL UNIQUE, -- admin, hr
    role_name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(120) NOT NULL UNIQUE,
    module_key VARCHAR(80) NOT NULL,
    action_key VARCHAR(40) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_permissions_module (module_key)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_role_permission (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id),
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_user_role (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE client_companies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_code VARCHAR(40) NOT NULL UNIQUE,
    company_name VARCHAR(190) NOT NULL,
    business_type VARCHAR(120) NOT NULL,
    contact_person VARCHAR(190) NULL,
    contact_number VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    office_address VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active', -- active, inactive
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    INDEX idx_client_companies_status (status),
    INDEX idx_client_companies_name (company_name),
    CONSTRAINT fk_client_companies_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_client_companies_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE client_departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_company_id BIGINT UNSIGNED NOT NULL,
    department_code VARCHAR(40) NOT NULL,
    department_name VARCHAR(190) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_client_department_code (client_company_id, department_code),
    UNIQUE KEY uq_client_department_name (client_company_id, department_name),
    INDEX idx_client_departments_status (status),
    CONSTRAINT fk_client_departments_company FOREIGN KEY (client_company_id) REFERENCES client_companies(id),
    CONSTRAINT fk_client_departments_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_client_departments_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE job_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(50) NOT NULL UNIQUE,
    client_company_id BIGINT UNSIGNED NOT NULL,
    client_department_id BIGINT UNSIGNED NOT NULL,
    position_title VARCHAR(150) NOT NULL,
    required_education VARCHAR(150) NULL,
    required_experience_months INT UNSIGNED NULL,
    required_certifications TEXT NULL,
    gender_preference VARCHAR(20) NULL,
    age_min TINYINT UNSIGNED NULL,
    age_max TINYINT UNSIGNED NULL,
    height_min_cm DECIMAL(5,2) NULL,
    physical_requirement TEXT NULL,
    availability_requirement VARCHAR(120) NULL,
    workers_needed INT UNSIGNED NOT NULL,
    workers_fulfilled INT UNSIGNED NOT NULL DEFAULT 0,
    date_requested DATE NOT NULL,
    deployment_deadline DATE NULL,
    request_status VARCHAR(40) NOT NULL DEFAULT 'open', -- open, in_progress, partially_fulfilled, fulfilled, closed, cancelled
    request_source VARCHAR(80) NULL,
    remarks TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    INDEX idx_job_requests_company (client_company_id),
    INDEX idx_job_requests_department (client_department_id),
    INDEX idx_job_requests_status (request_status),
    INDEX idx_job_requests_deadline (deployment_deadline),
    CONSTRAINT fk_job_requests_company FOREIGN KEY (client_company_id) REFERENCES client_companies(id),
    CONSTRAINT fk_job_requests_department FOREIGN KEY (client_department_id) REFERENCES client_departments(id),
    CONSTRAINT fk_job_requests_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_job_requests_approved_by FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE criteria_catalog (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    criteria_code VARCHAR(60) NOT NULL UNIQUE,
    criteria_name VARCHAR(120) NOT NULL,
    criteria_type VARCHAR(40) NOT NULL, -- hard_filter, weighted_binary, weighted_scale
    value_type VARCHAR(40) NOT NULL, -- boolean, number, text, enum
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_criteria_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE request_criteria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_request_id BIGINT UNSIGNED NOT NULL,
    criteria_id BIGINT UNSIGNED NOT NULL,
    mandatory_flag TINYINT(1) NOT NULL DEFAULT 0,
    weight_score DECIMAL(7,2) NOT NULL DEFAULT 0,
    expected_value VARCHAR(255) NULL,
    min_value DECIMAL(10,2) NULL,
    max_value DECIMAL(10,2) NULL,
    rubric_json JSON NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_request_criterion (job_request_id, criteria_id),
    INDEX idx_request_criteria_request (job_request_id),
    CONSTRAINT fk_request_criteria_request FOREIGN KEY (job_request_id) REFERENCES job_requests(id),
    CONSTRAINT fk_request_criteria_criteria FOREIGN KEY (criteria_id) REFERENCES criteria_catalog(id),
    CONSTRAINT fk_request_criteria_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE applicants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_code VARCHAR(50) NOT NULL UNIQUE,
    source_channel VARCHAR(40) NOT NULL, -- walk_in, messenger, email, online
    -- Set when the applicant registered themselves through the public site
    -- rather than being entered at the counter. Such a record is held before
    -- screening until an officer confirms the person's identity in person.
    self_registered_at TIMESTAMP NULL,
    identity_verified_at TIMESTAMP NULL,
    identity_verified_by BIGINT UNSIGNED NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    suffix VARCHAR(20) NULL,
    sex VARCHAR(20) NULL,
    birth_date DATE NULL,
    civil_status VARCHAR(30) NULL,
    nationality VARCHAR(80) NULL,
    height_cm DECIMAL(5,2) NULL,
    weight_kg DECIMAL(5,2) NULL,
    contact_number VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    present_address VARCHAR(255) NOT NULL,
    provincial_address VARCHAR(255) NULL,
    preferred_position VARCHAR(150) NULL,
    availability_date DATE NULL,
    distance_km DECIMAL(8,2) NULL,
    communication_rating DECIMAL(5,2) NULL,
    reliability_rating DECIMAL(5,2) NULL,
    application_date DATE NOT NULL,
    current_status VARCHAR(60) NOT NULL DEFAULT 'applied',
    folder_category VARCHAR(20) NOT NULL DEFAULT 'folder_3', -- folder_3, folder_2, folder_1
    remarks TEXT NULL,
    -- Nullable: a self-registered applicant was not entered by any staff
    -- member, and attributing the record to one would put a false name in the
    -- audit trail.
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    INDEX idx_applicants_status (current_status),
    INDEX idx_applicants_folder (folder_category),
    INDEX idx_applicants_name (last_name, first_name),
    INDEX idx_applicants_self_registered (self_registered_at),
    CONSTRAINT fk_applicants_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_applicants_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT fk_applicants_identity_verified_by FOREIGN KEY (identity_verified_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE applicant_educations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_id BIGINT UNSIGNED NOT NULL,
    education_level VARCHAR(80) NOT NULL,
    school_name VARCHAR(190) NOT NULL,
    course_program VARCHAR(190) NULL,
    graduation_year YEAR NULL,
    honors VARCHAR(120) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_applicant_educations_applicant (applicant_id),
    CONSTRAINT fk_applicant_educations_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id)
) ENGINE=InnoDB;

CREATE TABLE applicant_experiences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_id BIGINT UNSIGNED NOT NULL,
    company_name VARCHAR(190) NOT NULL,
    position_title VARCHAR(150) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    months_experience INT UNSIGNED NULL,
    responsibilities TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_applicant_experiences_applicant (applicant_id),
    CONSTRAINT fk_applicant_experiences_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id)
) ENGINE=InnoDB;

CREATE TABLE applicant_skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_id BIGINT UNSIGNED NOT NULL,
    skill_name VARCHAR(120) NOT NULL,
    proficiency_level VARCHAR(40) NULL,
    years_experience DECIMAL(5,2) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_applicant_skills_applicant (applicant_id),
    CONSTRAINT fk_applicant_skills_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id)
) ENGINE=InnoDB;

CREATE TABLE applicant_certifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_id BIGINT UNSIGNED NOT NULL,
    certification_name VARCHAR(150) NOT NULL,
    cert_number VARCHAR(80) NULL,
    issuer VARCHAR(150) NULL,
    issued_at DATE NULL,
    expires_at DATE NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_applicant_certifications_applicant (applicant_id),
    INDEX idx_applicant_certifications_expiry (expires_at),
    CONSTRAINT fk_applicant_certifications_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id)
) ENGINE=InnoDB;

CREATE TABLE requirement_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requirement_code VARCHAR(50) NOT NULL UNIQUE,
    requirement_name VARCHAR(150) NOT NULL,
    requirement_group VARCHAR(30) NOT NULL, -- primary, final
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    has_expiry TINYINT(1) NOT NULL DEFAULT 0,
    active_flag TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_requirement_types_group (requirement_group),
    INDEX idx_requirement_types_active (active_flag)
) ENGINE=InnoDB;

CREATE TABLE applicant_requirements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_id BIGINT UNSIGNED NOT NULL,
    requirement_type_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'missing', -- submitted, missing, pending, verified, rejected, expired
    file_path VARCHAR(255) NULL,
    file_name VARCHAR(190) NULL,
    file_mime VARCHAR(120) NULL,
    file_size_bytes BIGINT UNSIGNED NULL,
    submitted_at TIMESTAMP NULL,
    verified_at TIMESTAMP NULL,
    verified_by BIGINT UNSIGNED NULL,
    expiry_date DATE NULL,
    rejection_reason VARCHAR(255) NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_applicant_requirement (applicant_id, requirement_type_id),
    INDEX idx_applicant_requirements_status (status),
    INDEX idx_applicant_requirements_expiry (expiry_date),
    CONSTRAINT fk_applicant_requirements_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id),
    CONSTRAINT fk_applicant_requirements_type FOREIGN KEY (requirement_type_id) REFERENCES requirement_types(id),
    CONSTRAINT fk_applicant_requirements_verified_by FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE application_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(60) NULL,
    to_status VARCHAR(60) NOT NULL,
    reason VARCHAR(255) NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    changed_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_app_status_history_applicant (applicant_id),
    INDEX idx_app_status_history_to_status (to_status),
    CONSTRAINT fk_app_status_history_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id),
    CONSTRAINT fk_app_status_history_changed_by FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE trainings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    training_code VARCHAR(50) NOT NULL UNIQUE,
    training_title VARCHAR(190) NOT NULL,
    training_date DATE NOT NULL,
    location VARCHAR(190) NOT NULL,
    trainer_name VARCHAR(190) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'scheduled', -- scheduled, ongoing, completed, cancelled
    remarks TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_trainings_date (training_date),
    INDEX idx_trainings_status (status),
    CONSTRAINT fk_trainings_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE training_enrollments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    training_id BIGINT UNSIGNED NOT NULL,
    applicant_id BIGINT UNSIGNED NOT NULL,
    attendance_status VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending, present, absent
    completion_status VARCHAR(30) NOT NULL DEFAULT 'not_completed', -- not_completed, completed
    remarks TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_training_applicant (training_id, applicant_id),
    INDEX idx_training_enrollments_attendance (attendance_status),
    CONSTRAINT fk_training_enrollments_training FOREIGN KEY (training_id) REFERENCES trainings(id),
    CONSTRAINT fk_training_enrollments_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id)
) ENGINE=InnoDB;

CREATE TABLE job_request_matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_request_id BIGINT UNSIGNED NOT NULL,
    applicant_id BIGINT UNSIGNED NOT NULL,
    raw_score DECIMAL(10,2) NOT NULL,
    max_score DECIMAL(10,2) NOT NULL,
    percentage_score DECIMAL(6,2) NOT NULL,
    rank_order INT UNSIGNED NULL,
    recommendation_level VARCHAR(40) NOT NULL, -- highly_recommended, recommended, reserve_pool, not_recommended
    hard_filter_pass TINYINT(1) NOT NULL DEFAULT 1,
    breakdown_json JSON NOT NULL,
    evaluated_by BIGINT UNSIGNED NOT NULL,
    evaluated_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_match_request_applicant (job_request_id, applicant_id),
    INDEX idx_matches_request_rank (job_request_id, rank_order),
    INDEX idx_matches_percentage (percentage_score),
    CONSTRAINT fk_job_request_matches_request FOREIGN KEY (job_request_id) REFERENCES job_requests(id),
    CONSTRAINT fk_job_request_matches_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id),
    CONSTRAINT fk_job_request_matches_evaluated_by FOREIGN KEY (evaluated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE employees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    employee_number VARCHAR(60) NOT NULL UNIQUE,
    biometric_number VARCHAR(60) NULL,
    current_client_company_id BIGINT UNSIGNED NULL,
    current_department_id BIGINT UNSIGNED NULL,
    current_position_title VARCHAR(150) NULL,
    current_supervisor_name VARCHAR(190) NULL,
    hire_date DATE NOT NULL,
    employment_status VARCHAR(40) NOT NULL DEFAULT 'active', -- active, resigned, terminated, archived
    profile_notes TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    INDEX idx_employees_status (employment_status),
    INDEX idx_employees_company (current_client_company_id),
    CONSTRAINT fk_employees_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id),
    CONSTRAINT fk_employees_company FOREIGN KEY (current_client_company_id) REFERENCES client_companies(id),
    CONSTRAINT fk_employees_department FOREIGN KEY (current_department_id) REFERENCES client_departments(id),
    CONSTRAINT fk_employees_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_employees_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE deployments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deployment_code VARCHAR(60) NOT NULL UNIQUE,
    employee_id BIGINT UNSIGNED NOT NULL,
    job_request_id BIGINT UNSIGNED NOT NULL,
    client_company_id BIGINT UNSIGNED NOT NULL,
    client_department_id BIGINT UNSIGNED NOT NULL,
    position_title VARCHAR(150) NOT NULL,
    supervisor_name VARCHAR(190) NULL,
    deployment_date DATE NOT NULL,
    end_date DATE NULL,
    deployment_status VARCHAR(40) NOT NULL DEFAULT 'active', -- active, completed, transferred, ended
    remarks TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_deployments_employee (employee_id),
    INDEX idx_deployments_company (client_company_id),
    INDEX idx_deployments_status (deployment_status),
    CONSTRAINT fk_deployments_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_deployments_request FOREIGN KEY (job_request_id) REFERENCES job_requests(id),
    CONSTRAINT fk_deployments_company FOREIGN KEY (client_company_id) REFERENCES client_companies(id),
    CONSTRAINT fk_deployments_department FOREIGN KEY (client_department_id) REFERENCES client_departments(id),
    CONSTRAINT fk_deployments_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE deployment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deployment_id BIGINT UNSIGNED NOT NULL,
    from_company_id BIGINT UNSIGNED NULL,
    from_department_id BIGINT UNSIGNED NULL,
    from_position_title VARCHAR(150) NULL,
    to_company_id BIGINT UNSIGNED NULL,
    to_department_id BIGINT UNSIGNED NULL,
    to_position_title VARCHAR(150) NULL,
    change_type VARCHAR(40) NOT NULL, -- reassignment, transfer, return
    effective_date DATE NOT NULL,
    remarks TEXT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_deployment_history_deployment (deployment_id),
    CONSTRAINT fk_deployment_history_deployment FOREIGN KEY (deployment_id) REFERENCES deployments(id),
    CONSTRAINT fk_deployment_history_from_company FOREIGN KEY (from_company_id) REFERENCES client_companies(id),
    CONSTRAINT fk_deployment_history_from_department FOREIGN KEY (from_department_id) REFERENCES client_departments(id),
    CONSTRAINT fk_deployment_history_to_company FOREIGN KEY (to_company_id) REFERENCES client_companies(id),
    CONSTRAINT fk_deployment_history_to_department FOREIGN KEY (to_department_id) REFERENCES client_departments(id),
    CONSTRAINT fk_deployment_history_changed_by FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE employee_violations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    violation_date DATE NOT NULL,
    violation_type VARCHAR(60) NOT NULL, -- awol, absences, suspension, late, misconduct, policy_violation
    description TEXT NOT NULL,
    evidence_path VARCHAR(255) NULL,
    issued_by BIGINT UNSIGNED NOT NULL,
    remarks TEXT NULL,
    penalty VARCHAR(190) NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'open', -- open, under_review, resolved, escalated
    resolution_date DATE NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_employee_violations_employee (employee_id),
    INDEX idx_employee_violations_type (violation_type),
    INDEX idx_employee_violations_status (status),
    CONSTRAINT fk_employee_violations_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_employee_violations_issued_by FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE resignations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    resignation_letter_path VARCHAR(255) NULL,
    reason VARCHAR(255) NULL,
    rendering_days INT UNSIGNED NULL,
    filing_date DATE NOT NULL,
    exit_date DATE NULL,
    clearance_status VARCHAR(40) NOT NULL DEFAULT 'pending', -- pending, in_progress, cleared
    status VARCHAR(40) NOT NULL DEFAULT 'filed', -- filed, accepted, completed, withdrawn
    processed_by BIGINT UNSIGNED NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_resignations_employee (employee_id),
    INDEX idx_resignations_status (status),
    CONSTRAINT fk_resignations_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_resignations_processed_by FOREIGN KEY (processed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE terminations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    termination_date DATE NOT NULL,
    documents_path VARCHAR(255) NULL,
    remarks TEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'finalized', -- draft, for_review, finalized
    approved_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_terminations_employee (employee_id),
    INDEX idx_terminations_status (status),
    CONSTRAINT fk_terminations_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_terminations_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT fk_terminations_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE employee_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NOT NULL,
    reason VARCHAR(255) NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    changed_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_employee_status_history_employee (employee_id),
    CONSTRAINT fk_employee_status_history_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_employee_status_history_changed_by FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE archives (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(60) NOT NULL, -- applicant, employee, deployment, violation, resignation, termination
    entity_id BIGINT UNSIGNED NOT NULL,
    archive_reason VARCHAR(190) NULL,
    snapshot_json JSON NOT NULL,
    archived_by BIGINT UNSIGNED NOT NULL,
    archived_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_archives_entity (entity_type, entity_id),
    INDEX idx_archives_archived_at (archived_at),
    CONSTRAINT fk_archives_archived_by FOREIGN KEY (archived_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action_type VARCHAR(40) NOT NULL, -- login, create, update, delete, deployment, violation, resignation, termination
    module_key VARCHAR(80) NOT NULL,
    record_type VARCHAR(80) NULL,
    record_id BIGINT UNSIGNED NULL,
    old_values_json JSON NULL,
    new_values_json JSON NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    request_id VARCHAR(80) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_audit_logs_actor (actor_user_id),
    INDEX idx_audit_logs_action (action_type),
    INDEX idx_audit_logs_module (module_key),
    INDEX idx_audit_logs_record (record_type, record_id),
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Optional table for generated report metadata and async exports
CREATE TABLE report_exports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_type VARCHAR(80) NOT NULL,
    filter_json JSON NULL,
    file_path VARCHAR(255) NULL,
    export_format VARCHAR(20) NOT NULL, -- pdf, xlsx, csv
    status VARCHAR(30) NOT NULL DEFAULT 'queued', -- queued, processing, completed, failed
    requested_by BIGINT UNSIGNED NOT NULL,
    requested_at TIMESTAMP NOT NULL,
    completed_at TIMESTAMP NULL,
    error_message VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_report_exports_status (status),
    CONSTRAINT fk_report_exports_requested_by FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed hints (implement in Laravel seeders):
-- 1) roles: admin, hr, applicant, employee
-- 2) requirement_types: primary and final requirements from approved scope
-- 3) criteria_catalog: education, experience, skills, availability, distance, height, gender, certifications, communication, reliability
-- 4) permissions: module-based CRUD + workflow action permissions
