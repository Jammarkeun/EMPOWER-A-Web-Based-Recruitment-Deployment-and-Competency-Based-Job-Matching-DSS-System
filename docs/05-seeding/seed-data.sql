-- EMPOWER Seed Data Pack (MySQL)
-- Purpose: bootstrap reference records and sample workflow data

SET NAMES utf8mb4;

-- Roles
INSERT INTO roles (role_key, role_name, description, created_at, updated_at)
VALUES
('admin', 'Administrator', 'Full system administration', NOW(), NOW()),
('hr', 'HR Staff', 'Recruitment and deployment operations', NOW(), NOW()),
('applicant', 'Applicant Portal User', 'Applicant self-service portal account', NOW(), NOW()),
('employee', 'Employee Portal User', 'Employee self-service portal account', NOW(), NOW());

-- Core permissions
INSERT INTO permissions (permission_key, module_key, action_key, description, created_at, updated_at)
VALUES
('dashboard.view', 'dashboard', 'view', 'View dashboard metrics', NOW(), NOW()),
('clients.view', 'clients', 'view', 'View client companies', NOW(), NOW()),
('clients.create', 'clients', 'create', 'Create client company', NOW(), NOW()),
('clients.update', 'clients', 'update', 'Update client company', NOW(), NOW()),
('departments.manage', 'clients', 'manage_departments', 'Manage client departments', NOW(), NOW()),
('requests.view', 'job_requests', 'view', 'View job requests', NOW(), NOW()),
('requests.create', 'job_requests', 'create', 'Create job requests', NOW(), NOW()),
('requests.update', 'job_requests', 'update', 'Update job requests', NOW(), NOW()),
('requests.criteria', 'job_requests', 'criteria', 'Configure request criteria', NOW(), NOW()),
('applicants.view', 'applicants', 'view', 'View applicants', NOW(), NOW()),
('applicants.create', 'applicants', 'create', 'Create applicants', NOW(), NOW()),
('applicants.update', 'applicants', 'update', 'Update applicants', NOW(), NOW()),
('applicants.status', 'applicants', 'status_transition', 'Change applicant status', NOW(), NOW()),
('requirements.verify', 'requirements', 'verify', 'Verify applicant requirements', NOW(), NOW()),
('matching.evaluate', 'matching', 'evaluate', 'Run competency matching', NOW(), NOW()),
('training.manage', 'training', 'manage', 'Manage training schedules', NOW(), NOW()),
('deployments.create', 'deployments', 'create', 'Create deployments', NOW(), NOW()),
('deployments.update', 'deployments', 'update', 'Update deployments', NOW(), NOW()),
('employees.view', 'employees', 'view', 'View employees', NOW(), NOW()),
('employees.update', 'employees', 'update', 'Update employees', NOW(), NOW()),
('violations.manage', 'violations', 'manage', 'Manage employee violations', NOW(), NOW()),
('resignations.manage', 'separation', 'manage_resignation', 'Manage resignations', NOW(), NOW()),
('terminations.manage', 'separation', 'manage_termination', 'Manage terminations', NOW(), NOW()),
('archives.view', 'archives', 'view', 'View archived records', NOW(), NOW()),
('reports.generate', 'reports', 'generate', 'Generate reports', NOW(), NOW()),
('audit_logs.view', 'audit_logs', 'view', 'View audit logs', NOW(), NOW()),
('users.manage', 'access_control', 'manage_users', 'Manage users and roles', NOW(), NOW());

-- Assign permissions to admin role
INSERT INTO role_permissions (role_id, permission_id, created_at, updated_at)
SELECT r.id, p.id, NOW(), NOW()
FROM roles r
JOIN permissions p
WHERE r.role_key = 'admin';

-- Assign HR operational permissions
INSERT INTO role_permissions (role_id, permission_id, created_at, updated_at)
SELECT r.id, p.id, NOW(), NOW()
FROM roles r
JOIN permissions p
WHERE r.role_key = 'hr'
AND p.permission_key IN (
'dashboard.view','clients.view','clients.create','clients.update','departments.manage',
'requests.view','requests.create','requests.update','requests.criteria',
'applicants.view','applicants.create','applicants.update','applicants.status',
'requirements.verify','matching.evaluate','training.manage',
'deployments.create','deployments.update','employees.view','employees.update',
'violations.manage','resignations.manage','terminations.manage',
'archives.view','reports.generate','audit_logs.view'
);

-- Requirement types from approved scope
INSERT INTO requirement_types (requirement_code, requirement_name, requirement_group, is_required, has_expiry, active_flag, created_at, updated_at)
VALUES
('resume', 'Resume', 'primary', 1, 0, 1, NOW(), NOW()),
('biodata', 'Bio-data', 'primary', 1, 0, 1, NOW(), NOW()),
('birth_certificate', 'Birth Certificate', 'primary', 1, 0, 1, NOW(), NOW()),
('diploma', 'Diploma', 'primary', 1, 0, 1, NOW(), NOW()),
('sss', 'SSS', 'primary', 1, 0, 1, NOW(), NOW()),
('philhealth', 'PhilHealth', 'primary', 1, 0, 1, NOW(), NOW()),
('pagibig', 'Pag-IBIG', 'primary', 1, 0, 1, NOW(), NOW()),
('police_clearance', 'Police Clearance', 'primary', 1, 1, 1, NOW(), NOW()),
('barangay_clearance', 'Barangay Clearance', 'primary', 1, 1, 1, NOW(), NOW()),
('coe', 'Certificate of Employment', 'primary', 0, 0, 1, NOW(), NOW()),
('marriage_certificate', 'Marriage Certificate', 'primary', 0, 0, 1, NOW(), NOW()),
('drug_test', 'Drug Test', 'final', 1, 1, 1, NOW(), NOW()),
('urine_test', 'Urine Test', 'final', 1, 1, 1, NOW(), NOW()),
('stool_test', 'Stool Test', 'final', 1, 1, 1, NOW(), NOW()),
('hepa_b', 'Hepa B', 'final', 1, 1, 1, NOW(), NOW()),
('health_card', 'Health Card', 'final', 1, 1, 1, NOW(), NOW()),
('medical_results', 'Medical Results', 'final', 1, 1, 1, NOW(), NOW());

-- Criteria catalog
INSERT INTO criteria_catalog (criteria_code, criteria_name, criteria_type, value_type, description, is_active, created_at, updated_at)
VALUES
('education', 'Education', 'weighted_binary', 'enum', 'Educational attainment requirement', 1, NOW(), NOW()),
('experience', 'Experience', 'weighted_scale', 'number', 'Relevant work experience in months', 1, NOW(), NOW()),
('skills', 'Skills', 'weighted_scale', 'text', 'Role-relevant technical skills', 1, NOW(), NOW()),
('availability', 'Availability', 'weighted_binary', 'enum', 'Availability to start date', 1, NOW(), NOW()),
('distance', 'Distance', 'weighted_scale', 'number', 'Distance from assignment site', 1, NOW(), NOW()),
('height', 'Height', 'hard_filter', 'number', 'Minimum physical height requirement', 1, NOW(), NOW()),
('gender', 'Gender', 'weighted_binary', 'enum', 'Gender preference if declared by client', 1, NOW(), NOW()),
('certifications', 'Certifications', 'hard_filter', 'text', 'Required certifications like NC II', 1, NOW(), NOW()),
('communication', 'Communication', 'weighted_scale', 'enum', 'Interview communication rating', 1, NOW(), NOW()),
('reliability', 'Reliability', 'weighted_scale', 'enum', 'Reliability screening rating', 1, NOW(), NOW());

-- Sample users (replace password hashes in production seeder)
INSERT INTO users (
first_name, last_name, email, mobile_number, password, user_type, is_active, created_at, updated_at
)
VALUES
('System', 'Administrator', 'admin@cde.local', '09170000001', '$2y$12$replace_with_bcrypt_hash', 'admin', 1, NOW(), NOW()),
('Core', 'HR', 'hr@cde.local', '09170000002', '$2y$12$replace_with_bcrypt_hash', 'hr', 1, NOW(), NOW());

-- Assign user roles
INSERT INTO user_roles (user_id, role_id, created_at, updated_at)
SELECT u.id, r.id, NOW(), NOW()
FROM users u
JOIN roles r ON r.role_key = 'admin'
WHERE u.email = 'admin@cde.local';

INSERT INTO user_roles (user_id, role_id, created_at, updated_at)
SELECT u.id, r.id, NOW(), NOW()
FROM users u
JOIN roles r ON r.role_key = 'hr'
WHERE u.email = 'hr@cde.local';

-- Sample client company and departments
INSERT INTO client_companies (
company_code, company_name, business_type, contact_person, contact_number, email, office_address, status, created_at, updated_at
)
VALUES
('CDE-CLI-001', 'Best TV Food Products Corporation', 'Food Manufacturing', 'Angela Reyes', '0495551200', 'besttv@example.local', 'Sta. Cruz, Laguna', 'active', NOW(), NOW());

INSERT INTO client_departments (client_company_id, department_code, department_name, status, created_at, updated_at)
SELECT cc.id, 'CHOCO', 'Chocolate Department', 'active', NOW(), NOW() FROM client_companies cc WHERE cc.company_code = 'CDE-CLI-001'
UNION ALL
SELECT cc.id, 'CANDY', 'Candy Department', 'active', NOW(), NOW() FROM client_companies cc WHERE cc.company_code = 'CDE-CLI-001'
UNION ALL
SELECT cc.id, 'PACK', 'Packaging', 'active', NOW(), NOW() FROM client_companies cc WHERE cc.company_code = 'CDE-CLI-001'
UNION ALL
SELECT cc.id, 'WARE', 'Warehouse', 'active', NOW(), NOW() FROM client_companies cc WHERE cc.company_code = 'CDE-CLI-001'
UNION ALL
SELECT cc.id, 'PROD', 'Production', 'active', NOW(), NOW() FROM client_companies cc WHERE cc.company_code = 'CDE-CLI-001'
UNION ALL
SELECT cc.id, 'QC', 'Quality Control', 'active', NOW(), NOW() FROM client_companies cc WHERE cc.company_code = 'CDE-CLI-001';

-- Sample job request
INSERT INTO job_requests (
request_code, client_company_id, client_department_id, position_title,
required_education, required_experience_months, required_certifications,
gender_preference, age_min, age_max, height_min_cm, physical_requirement,
availability_requirement, workers_needed, workers_fulfilled, date_requested,
deployment_deadline, request_status, request_source, created_by, created_at, updated_at
)
SELECT
'JR-2026-0001',
cc.id,
cd.id,
'Production Helper',
'High School Graduate',
6,
'NC II',
'any',
18,
35,
152,
'Fit to lift 15kg',
'Can start within 7 days',
15,
0,
CURDATE(),
DATE_ADD(CURDATE(), INTERVAL 14 DAY),
'open',
'email',
u.id,
NOW(),
NOW()
FROM client_companies cc
JOIN client_departments cd ON cd.client_company_id = cc.id AND cd.department_code = 'PROD'
JOIN users u ON u.email = 'hr@cde.local'
WHERE cc.company_code = 'CDE-CLI-001';

-- Request criteria sample for JR-2026-0001
INSERT INTO request_criteria (
job_request_id, criteria_id, mandatory_flag, weight_score, expected_value, min_value, rubric_json, created_by, created_at, updated_at
)
SELECT jr.id, c.id,
CASE WHEN c.criteria_code IN ('education','certifications') THEN 1 ELSE 0 END,
CASE c.criteria_code
WHEN 'education' THEN 15
WHEN 'experience' THEN 20
WHEN 'certifications' THEN 10
WHEN 'distance' THEN 5
WHEN 'availability' THEN 15
WHEN 'communication' THEN 20
WHEN 'reliability' THEN 15
ELSE 0 END,
CASE c.criteria_code
WHEN 'education' THEN 'high_school_or_above'
WHEN 'certifications' THEN 'NC II'
WHEN 'availability' THEN 'within_7_days'
ELSE NULL END,
CASE c.criteria_code
WHEN 'experience' THEN 6
WHEN 'distance' THEN NULL
ELSE NULL END,
CASE c.criteria_code
WHEN 'communication' THEN JSON_OBJECT('excellent',1.0,'good',0.8,'fair',0.5,'poor',0.2)
WHEN 'reliability' THEN JSON_OBJECT('excellent',1.0,'good',0.8,'fair',0.5,'poor',0.2)
ELSE NULL END,
(SELECT id FROM users WHERE email = 'hr@cde.local' LIMIT 1),
NOW(), NOW()
FROM job_requests jr
JOIN criteria_catalog c
WHERE jr.request_code = 'JR-2026-0001'
AND c.criteria_code IN ('education','experience','certifications','distance','availability','communication','reliability');
