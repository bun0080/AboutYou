-- MySQL 8.0.46-0

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

CREATE DATABASE `aboutyou_website` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `aboutyou_website`;

CREATE TABLE `tbl_device_auth` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `device_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'mobile/tablet/desktop',
  `last_login` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_id` (`device_id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `tbl_device_auth_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tbl_user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `tbl_memories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'User who uploaded/created the memory (FK to tbl_user.id)',
  `capsule_id` int DEFAULT NULL COMMENT 'If the memory belongs to a time capsule (FK to tbl_time_capsules.id)',
  `type` varchar(50) NOT NULL COMMENT 'e.g., photo, video, note, milestone',
  `content_text` text COMMENT 'For notes or descriptions',
  `media_url` varchar(255) DEFAULT NULL COMMENT 'URL to stored photo/video',
  `thumbnail_url` varchar(255) DEFAULT NULL COMMENT 'Thumbnail for media',
  `capture_date` date NOT NULL COMMENT 'When the memory was captured (for grouping)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `visibility` varchar(50) DEFAULT 'private' COMMENT 'e.g., private, friends, public',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `capsule_id` (`capsule_id`),
  CONSTRAINT `tbl_memories_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tbl_user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_memories_ibfk_2` FOREIGN KEY (`capsule_id`) REFERENCES `tbl_time_capsules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5081 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `tbl_memory_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT '留言者',
  `capsule_id` int DEFAULT NULL COMMENT '所屬膠囊 (如果是通用回憶則為 NULL)',
  `target_date` date NOT NULL COMMENT '目標回憶日期 (對應 capture_date)',
  `comment_text` text NOT NULL COMMENT '留言內容',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `tbl_memory_shared` (
  `memory_id` int NOT NULL,
  `target_user_ids` text NOT NULL,
  PRIMARY KEY (`memory_id`),
  CONSTRAINT `tbl_memory_shared_ibfk_1` FOREIGN KEY (`memory_id`) REFERENCES `tbl_memories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `tbl_milestones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'User associated with the milestone (FK to tbl_user.id)',
  `capsule_id` int DEFAULT NULL,
  `memory_id` int DEFAULT NULL COMMENT 'Optional link to a specific memory (FK to tbl_memories.id)',
  `milestone_type` varchar(100) NOT NULL COMMENT 'e.g., first step, first word, weight, height',
  `value` varchar(255) DEFAULT NULL COMMENT 'e.g., "10 kg", "75 cm"',
  `notes` text,
  `milestone_date` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `milestone_updated` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_date` (`user_id`,`milestone_date`),
  KEY `memory_id` (`memory_id`),
  KEY `idx_capsule_date` (`capsule_id`,`milestone_date`),
  CONSTRAINT `tbl_milestones_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tbl_user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_milestones_ibfk_2` FOREIGN KEY (`memory_id`) REFERENCES `tbl_memories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `tbl_time_capsules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'Owner of the capsule (FK to tbl_user.id)',
  `title` varchar(255) NOT NULL,
  `description` text,
  `profile_image_url` varchar(255) DEFAULT NULL COMMENT 'URL to capsule profile image',
  `delivery_date` datetime NOT NULL COMMENT 'When the capsule should be opened',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(50) DEFAULT 'pending' COMMENT 'e.g., pending, delivered, opened',
  `is_default` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `tbl_time_capsules_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tbl_user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `tbl_user` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(30) NOT NULL,
  `nickname` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icon_url` varchar(255) DEFAULT NULL,
  `relationship` varchar(50) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `approval` varchar(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT 'N',
  `aboutyou_default_capsule` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_default_capsule` (`aboutyou_default_capsule`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=latin1;


-- 2026-08-17 09:14:56
