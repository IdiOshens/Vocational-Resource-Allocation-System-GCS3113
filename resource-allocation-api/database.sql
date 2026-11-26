-- =============================================================================
-- Database Schema & Seed Data for Vocational School Resource Allocation System
-- Target Database: school_resource_db
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `school_resource_db`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `school_resource_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Table: users
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `role` enum('admin','department_head','instructor','student','staff') NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `account_status` enum('pending','active','rejected','suspended') DEFAULT 'active',
  `is_sso_user` tinyint(1) DEFAULT 0,
  `sso_provider` varchar(50) DEFAULT NULL,
  `sso_id` varchar(255) DEFAULT NULL,
  `booking_privileges` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`booking_privileges`)),
  `profile_picture_url` varchar(255) DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  KEY `department_id` (`department_id`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: departments
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_code` varchar(20) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `head_user_id` int(11) DEFAULT NULL,
  `campus_location` varchar(50) NOT NULL,
  `building` varchar(50) DEFAULT NULL,
  `budget` decimal(15,2) DEFAULT 0.00,
  `phone_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `department_code` (`department_code`),
  KEY `fk_department_head` (`head_user_id`),
  CONSTRAINT `fk_department_head` FOREIGN KEY (`head_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- Table: students
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `student_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `student_number` varchar(50) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `program` varchar(100) DEFAULT NULL,
  `year_of_study` int(11) DEFAULT 1,
  `enrollment_status` enum('active','graduated','withdrawn','suspended') DEFAULT 'active',
  `enrolled_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `student_number` (`student_number`),
  KEY `department_id` (`department_id`),
  KEY `course_id` (`course_id`),
  KEY `idx_student_number` (`student_number`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `students_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `students_ibfk_3` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE SET NULL,
  CONSTRAINT `students_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `students_ibfk_5` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: instructors
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `instructors`;
CREATE TABLE `instructors` (
  `instructor_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `max_hours_per_week` int(11) DEFAULT 20,
  `preferred_room_type` varchar(50) DEFAULT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `qualification` varchar(200) DEFAULT NULL,
  `years_of_experience` int(11) DEFAULT 0,
  PRIMARY KEY (`instructor_id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `department_id` (`department_id`),
  KEY `idx_availability` (`is_available`),
  CONSTRAINT `instructors_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `instructors_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: staff
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `staff`;
CREATE TABLE `staff` (
  `staff_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_department` (`department_id`),
  KEY `idx_available` (`is_available`),
  CONSTRAINT `staff_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `staff_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: classrooms
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `classrooms`;
CREATE TABLE `classrooms` (
  `classroom_id` int(11) NOT NULL AUTO_INCREMENT,
  `room_number` varchar(20) NOT NULL,
  `building` varchar(50) NOT NULL,
  `floor` int(11) DEFAULT NULL,
  `campus_location` varchar(50) NOT NULL,
  `capacity` int(11) NOT NULL CHECK (`capacity` > 0),
  `room_type` enum('lecture','lab','workshop','seminar','computer_lab','study_pod') NOT NULL,
  `has_projector` tinyint(1) DEFAULT 0,
  `has_smart_board` tinyint(1) DEFAULT 0,
  `has_computers` tinyint(1) DEFAULT 0,
  `number_of_computers` int(11) DEFAULT 0,
  `has_air_conditioning` tinyint(1) DEFAULT 0,
  `has_wifi` tinyint(1) DEFAULT 1,
  `requires_keycard` tinyint(1) DEFAULT 0,
  `safety_level` enum('low','medium','high') DEFAULT 'low',
  `status` enum('available','maintenance','unavailable','reserved') DEFAULT 'available',
  `transit_buffer_minutes` int(11) DEFAULT 30,
  `last_maintenance` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`classroom_id`),
  UNIQUE KEY `unique_room` (`room_number`,`building`),
  KEY `idx_campus` (`campus_location`),
  KEY `idx_type` (`room_type`),
  KEY `idx_status` (`status`),
  CONSTRAINT `classrooms_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `classrooms_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: equipment
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `equipment`;
CREATE TABLE `equipment` (
  `equipment_id` int(11) NOT NULL AUTO_INCREMENT,
  `equipment_name` varchar(100) NOT NULL,
  `equipment_type` varchar(50) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `serial_number` varchar(100) NOT NULL,
  `asset_tag` varchar(50) DEFAULT NULL,
  `manufacturer` varchar(100) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_price` decimal(10,2) DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL,
  `battery_health` int(11) DEFAULT NULL CHECK (`battery_health` >= 0 and `battery_health` <= 100),
  `connectivity_type` varchar(50) DEFAULT NULL,
  `requires_supervision` tinyint(1) DEFAULT 0,
  `safety_level` enum('low','medium','high') DEFAULT 'low',
  `maintenance_interval_days` int(11) DEFAULT 30,
  `last_maintenance` date DEFAULT NULL,
  `next_maintenance` date DEFAULT NULL,
  `status` enum('available','in_use','maintenance','damaged','disposed') DEFAULT 'available',
  `current_location` varchar(100) DEFAULT NULL,
  `responsible_department_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`equipment_id`),
  UNIQUE KEY `serial_number` (`serial_number`),
  UNIQUE KEY `asset_tag` (`asset_tag`),
  KEY `responsible_department_id` (`responsible_department_id`),
  KEY `idx_type` (`equipment_type`),
  KEY `idx_status` (`status`),
  KEY `idx_serial` (`serial_number`),
  CONSTRAINT `equipment_ibfk_1` FOREIGN KEY (`responsible_department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_ibfk_3` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: courses
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `courses`;
CREATE TABLE `courses` (
  `course_id` int(11) NOT NULL AUTO_INCREMENT,
  `course_code` varchar(20) NOT NULL,
  `course_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `credits` int(11) DEFAULT 3,
  `duration_weeks` int(11) DEFAULT 12,
  `hours_per_week` int(11) DEFAULT 3,
  `course_level` enum('certificate','diploma','degree','advanced_diploma') NOT NULL,
  `requires_lab` tinyint(1) DEFAULT 0,
  `requires_special_equipment` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`course_id`),
  UNIQUE KEY `course_code` (`course_code`),
  KEY `department_id` (`department_id`),
  KEY `idx_code` (`course_code`),
  CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `courses_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `courses_ibfk_3` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: time_slots
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `time_slots`;
CREATE TABLE `time_slots` (
  `slot_id` int(11) NOT NULL AUTO_INCREMENT,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_recurring` tinyint(1) DEFAULT 1,
  `slot_type` enum('theory','practical','lab','exam','event','study') NOT NULL,
  PRIMARY KEY (`slot_id`),
  KEY `idx_day` (`day_of_week`),
  KEY `idx_time_range` (`start_time`,`end_time`),
  CONSTRAINT `valid_time` CHECK (`start_time` < `end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: resource_tags
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `resource_tags`;
CREATE TABLE `resource_tags` (
  `tag_id` int(11) NOT NULL AUTO_INCREMENT,
  `tag_name` varchar(50) NOT NULL,
  `tag_category` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`tag_id`),
  UNIQUE KEY `tag_name` (`tag_name`),
  KEY `idx_name` (`tag_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: classroom_tags
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `classroom_tags`;
CREATE TABLE `classroom_tags` (
  `classroom_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`classroom_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `classroom_tags_ibfk_1` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`classroom_id`) ON DELETE CASCADE,
  CONSTRAINT `classroom_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `resource_tags` (`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: equipment_tags
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `equipment_tags`;
CREATE TABLE `equipment_tags` (
  `equipment_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`equipment_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `equipment_tags_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE,
  CONSTRAINT `equipment_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `resource_tags` (`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: allocations
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `allocations`;
CREATE TABLE `allocations` (
  `allocation_id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_type` enum('classroom','equipment','instructor') NOT NULL,
  `resource_id` int(11) NOT NULL,
  `booked_by_user_id` int(11) NOT NULL,
  `approver_user_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `slot_id` int(11) DEFAULT NULL,
  `allocation_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `purpose` text NOT NULL,
  `is_recurring` tinyint(1) DEFAULT 0,
  `recurrence_pattern` varchar(50) DEFAULT NULL,
  `recurrence_end_date` date DEFAULT NULL,
  `recurrence_uuid` varchar(36) DEFAULT NULL,
  `priority` enum('low','medium','high','emergency') DEFAULT 'medium',
  `status` enum('pending','approved','rejected','cancelled','completed') DEFAULT 'pending',
  `approval_notes` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `checked_in_at` timestamp NULL DEFAULT NULL,
  `checked_out_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`allocation_id`),
  KEY `approver_user_id` (`approver_user_id`),
  KEY `department_id` (`department_id`),
  KEY `course_id` (`course_id`),
  KEY `slot_id` (`slot_id`),
  KEY `idx_resource` (`resource_type`,`resource_id`),
  KEY `idx_date_time` (`allocation_date`,`start_time`,`end_time`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`booked_by_user_id`),
  CONSTRAINT `allocations_ibfk_1` FOREIGN KEY (`booked_by_user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `allocations_ibfk_2` FOREIGN KEY (`approver_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `allocations_ibfk_3` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `allocations_ibfk_4` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE SET NULL,
  CONSTRAINT `allocations_ibfk_5` FOREIGN KEY (`slot_id`) REFERENCES `time_slots` (`slot_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: equipment_borrowing
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `equipment_borrowing`;
CREATE TABLE `equipment_borrowing` (
  `borrowing_id` int(11) NOT NULL AUTO_INCREMENT,
  `equipment_id` int(11) NOT NULL,
  `borrowed_by_user_id` int(11) NOT NULL,
  `borrowed_from_date` datetime NOT NULL,
  `borrowed_until_date` datetime NOT NULL,
  `actual_return_date` datetime DEFAULT NULL,
  `purpose` text NOT NULL,
  `responsible_instructor_id` int(11) DEFAULT NULL,
  `requires_endorsement` tinyint(1) DEFAULT 0,
  `endorsement_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `endorsed_by_user_id` int(11) DEFAULT NULL,
  `condition_borrowed` text DEFAULT NULL,
  `condition_returned` text DEFAULT NULL,
  `status` enum('borrowed','returned','overdue','damaged','lost') DEFAULT 'borrowed',
  `late_fee` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`borrowing_id`),
  KEY `borrowed_by_user_id` (`borrowed_by_user_id`),
  KEY `responsible_instructor_id` (`responsible_instructor_id`),
  KEY `endorsed_by_user_id` (`endorsed_by_user_id`),
  KEY `idx_equipment` (`equipment_id`),
  KEY `idx_status` (`status`),
  KEY `idx_dates` (`borrowed_from_date`,`borrowed_until_date`),
  CONSTRAINT `equipment_borrowing_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`),
  CONSTRAINT `equipment_borrowing_ibfk_2` FOREIGN KEY (`borrowed_by_user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `equipment_borrowing_ibfk_3` FOREIGN KEY (`responsible_instructor_id`) REFERENCES `instructors` (`instructor_id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_borrowing_ibfk_4` FOREIGN KEY (`endorsed_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: maintenance_records
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `maintenance_records`;
CREATE TABLE `maintenance_records` (
  `maintenance_id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_type` enum('classroom','equipment') NOT NULL,
  `resource_id` int(11) NOT NULL,
  `maintenance_date` date NOT NULL,
  `maintenance_type` enum('routine','repair','emergency','inspection') NOT NULL,
  `description` text NOT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `performed_by_user_id` int(11) DEFAULT NULL,
  `vendor_name` varchar(100) DEFAULT NULL,
  `parts_used` text DEFAULT NULL,
  `next_maintenance_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`maintenance_id`),
  KEY `performed_by_user_id` (`performed_by_user_id`),
  KEY `idx_resource` (`resource_type`,`resource_id`),
  KEY `idx_date` (`maintenance_date`),
  CONSTRAINT `maintenance_records_ibfk_1` FOREIGN KEY (`performed_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: notifications
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `type` enum('booking_confirmation','reminder','overdue','rejection','approval','maintenance','endorsement','strike_warning','general') NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `sent_via_push` tinyint(1) DEFAULT 0,
  `sent_via_email` tinyint(1) DEFAULT 0,
  `push_sent_at` timestamp NULL DEFAULT NULL,
  `email_sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: api_tokens
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `api_tokens`;
CREATE TABLE `api_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_token_user` (`user_id`),
  KEY `idx_token_expires` (`expires_at`),
  KEY `idx_token_revoked` (`revoked_at`),
  CONSTRAINT `api_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: password_reset_tokens
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  KEY `idx_token` (`token_hash`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: user_strikes
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `user_strikes`;
CREATE TABLE `user_strikes` (
  `strike_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `strike_date` datetime NOT NULL,
  `reason` enum('no_show','late_cancellation','damage','misuse') NOT NULL,
  `allocation_id` int(11) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`strike_id`),
  KEY `allocation_id` (`allocation_id`),
  KEY `idx_user_active` (`user_id`,`is_active`),
  KEY `idx_expiry` (`expires_at`),
  CONSTRAINT `user_strikes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_strikes_ibfk_2` FOREIGN KEY (`allocation_id`) REFERENCES `allocations` (`allocation_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: audit_logs
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `table_name` varchar(50) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `device_id` varchar(255) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: system_settings
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text NOT NULL,
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `updated_by_user_id` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `updated_by_user_id` (`updated_by_user_id`),
  KEY `idx_key` (`setting_key`),
  CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- Basic Seed Data
-- =============================================================================

-- 1. Departments (2 departments)
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `campus_location`, `building`, `budget`, `email`)
VALUES
  (1, 'IT', 'Information Technology', 'Main Campus', 'Block A', 25000000.00, 'it.dept@school.ac.ug'),
  (2, 'EE', 'Electrical Engineering', 'Main Campus', 'Block B', 30000000.00, 'ee.dept@school.ac.ug')
ON DUPLICATE KEY UPDATE `department_name` = VALUES(`department_name`);

-- 2. Admin User (Password: Admin123!)
INSERT INTO `users` (`user_id`, `email`, `username`, `password_hash`, `full_name`, `phone_number`, `role`, `department_id`, `is_active`, `account_status`)
VALUES
  (1, 'admin@school.ac.ug', 'admin', '$2y$10$61/bvGGCIm5hgf6221vgL.iRn6Nu3SSFSfSvghfaSGcb2qdB.kkPm', 'System Administrator', '+256700000001', 'admin', 1, 1, 'active')
ON DUPLICATE KEY UPDATE `email` = VALUES(`email`);

-- Update department head link
UPDATE `departments` SET `head_user_id` = 1 WHERE `department_id` = 1;

-- 3. Classrooms (2 classrooms)
INSERT INTO `classrooms` (`classroom_id`, `room_number`, `building`, `floor`, `campus_location`, `capacity`, `room_type`, `has_projector`, `has_smart_board`, `has_computers`, `number_of_computers`, `has_wifi`, `status`)
VALUES
  (1, '101A', 'Block A', 1, 'Main Campus', 40, 'computer_lab', 1, 1, 1, 40, 1, 'available'),
  (2, '102A', 'Block A', 1, 'Main Campus', 30, 'lecture', 1, 0, 0, 0, 1, 'available')
ON DUPLICATE KEY UPDATE `capacity` = VALUES(`capacity`);

-- 4. Equipment Items (2 equipment items)
INSERT INTO `equipment` (`equipment_id`, `equipment_name`, `equipment_type`, `model`, `serial_number`, `asset_tag`, `manufacturer`, `status`, `current_location`, `responsible_department_id`)
VALUES
  (1, 'Projector Epson EB-X51', 'Projector', 'EB-X51', 'PRJ-001', 'TAG-PRJ-001', 'Epson', 'available', 'Block A - Media Room', 1),
  (2, 'Dell Latitude 3420', 'Laptop', 'Latitude 3420', 'LAP-001', 'TAG-LAP-001', 'Dell', 'available', 'Block A - IT Lab', 1)
ON DUPLICATE KEY UPDATE `equipment_name` = VALUES(`equipment_name`);

-- 5. Courses (4 courses across 2 departments)
INSERT INTO `courses` (`course_id`, `course_code`, `course_name`, `department_id`, `credits`, `course_level`)
VALUES
  (1, 'CS101', 'Diploma in Computer Science and IT', 1, 3, 'diploma'),
  (2, 'IT201', 'Certificate in Information Technology', 1, 3, 'certificate'),
  (3, 'EE101', 'Diploma in Electrical Engineering', 2, 3, 'diploma'),
  (4, 'EE201', 'Certificate in Electrical Installation', 2, 3, 'certificate')
ON DUPLICATE KEY UPDATE `course_name` = VALUES(`course_name`);

SET FOREIGN_KEY_CHECKS = 1;
