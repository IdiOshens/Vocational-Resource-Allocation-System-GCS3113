-- Migration: Update students and add audit columns
USE `school_resource_db`;

ALTER TABLE `students`
  MODIFY COLUMN `user_id` INT(11) NULL DEFAULT NULL,
  ADD COLUMN `full_name` VARCHAR(100) NULL AFTER `student_number`,
  ADD COLUMN `email` VARCHAR(255) NULL AFTER `full_name`,
  ADD COLUMN `course_id` INT(11) NULL AFTER `department_id`,
  ADD COLUMN `created_by` INT(11) NULL,
  ADD COLUMN `updated_by` INT(11) NULL,
  ADD CONSTRAINT `fk_students_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_students_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_students_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `classrooms`
  ADD COLUMN `created_by` INT(11) NULL,
  ADD COLUMN `updated_by` INT(11) NULL,
  ADD CONSTRAINT `fk_classrooms_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_classrooms_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `equipment`
  ADD COLUMN `created_by` INT(11) NULL,
  ADD COLUMN `updated_by` INT(11) NULL,
  ADD CONSTRAINT `fk_equipment_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_equipment_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `courses`
  ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  ADD COLUMN `created_by` INT(11) NULL,
  ADD COLUMN `updated_by` INT(11) NULL,
  ADD CONSTRAINT `fk_courses_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_courses_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
